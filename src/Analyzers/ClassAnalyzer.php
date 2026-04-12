<?php

namespace Quatrebarbes\Larchiclass\Analyzers;

use ReflectionClass;
use ReflectionIntersectionType;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionProperty;
use ReflectionUnionType;

class ClassAnalyzer
{
    /**
     * Discover all FQCNs in a given namespace by scanning Composer's classmap,
     * with a filesystem fallback (PSR-4 convention).
     */
    public function listClasses(string $namespace, bool $isEloquent): array
    {
        $namespace = rtrim($namespace, '\\');
        $namespace = str_replace('\\', '\\\\', $namespace);

        $classes = array_keys($this->getComposerClassMap());
        $classes = array_filter($classes, fn (string $fqcn) => preg_match('/^' . $namespace . '(\\\\|$)/', $fqcn));
        $classes = array_filter($classes, fn (string $fqcn) => ! $this->isVendorClass($fqcn));
        if ($isEloquent) {
            $classes = array_filter($classes, function (string $fqcn) {
                $ref = new ReflectionClass($fqcn);
                return $ref->isSubclassOf('Illuminate\Database\Eloquent\Model');
            });
        }

        return $classes;
    }

    /**
     * Build detailed entries for targeted classes
     */
    public function readTargetedClasses(string $fqcn, bool $isEloquentModel = false): array
    {
        $ref = new ReflectionClass($fqcn);

        $parentFqcn = $ref->getParentClass() ? $ref->getParentClass()->getName() : null;
        $interfaces = array_values($ref->getInterfaceNames());
        $traits     = array_values($ref->getTraitNames());

        $structuralFqcns = array_filter(array_merge(
            $parentFqcn ? [$parentFqcn] : [],
            $interfaces,
            $traits,
        ));

        $relations = [];
        if ($isEloquentModel) {
            $eloquentRelationshipExtractor = new EloquentRelationshipExtractor();
            $relations = $eloquentRelationshipExtractor->extract($fqcn);
        }

        return [
            'fqcn'         => $fqcn,
            'name'         => $ref->getShortName(),
            'namespace'    => $ref->getNamespaceName(),
            'isAbstract'   => $ref->isAbstract() && ! $ref->isInterface(),
            'isInterface'  => $ref->isInterface(),
            'isTrait'      => $ref->isTrait(),
            'isEnum'       => $ref->isEnum(),
            'isEloquent'   => $isEloquentModel,
            'isVendor'     => false,
            'parent'       => $parentFqcn,
            'interfaces'   => $interfaces,
            'traits'       => $traits,
            'relations'    => $relations,
            'dependencies' => $this->extractDependencies($ref, $structuralFqcns),
            'properties'   => $isEloquentModel
                ? $this->extractEloquentProperties($ref)
                : $this->extractProperties($ref),
            'methods'      => array_filter(
                $this->extractMethods($ref),
                fn($method) => !in_array($method['name'], array_column($relations, 'method'))
            ),
        ];
    }

    /**
     * Build stub entries for related classes referenced as parents / interfaces / traits.
     */
    public function readRelatedClasses(array $classDataList, bool $withVendors): array
    {
        $knownFqcn = array_column($classDataList, 'fqcn');
        $stubs     = [];
        $seen      = [];

        foreach ($classDataList as $class) {

            // Merge all king of related classes
            $refs = array_filter(array_merge(
                $class['parent'] ? [$class['parent']] : [],
                $class['interfaces'],
                $class['traits'],
                array_column($class['relations'], 'relatedFqcn'),
                $class['dependencies'],
            ));

            foreach ($refs as $fqcn) {
                if (in_array($fqcn, $knownFqcn, true) || isset($seen[$fqcn])) {
                    continue;
                }

                $seen[$fqcn] = true;

                if (! $this->isVendorClass($fqcn) || $withVendors) {
                    $stubs[] = $this->makeStub($fqcn);
                }
            }
        }

        return $stubs;
    }

    // -------------------------------------------------------------------------
    // Public helper
    // -------------------------------------------------------------------------

    public function isVendorClass(string $fqcn): bool
    {
        $classMap = $this->getComposerClassMap();

        if (isset($classMap[$fqcn])) {
            return str_contains(str_replace('\\', '/', $classMap[$fqcn]), '/vendor/');
        }

        try {
            $ref = new ReflectionClass($fqcn);
            if ($ref->isInternal()) {
                return true; // hide internal classes, like vendors
            }
            $file = $ref->getFileName();
            return $file !== false && str_contains(str_replace('\\', '/', $file), '/vendor/');
        } catch (\Throwable) {
            return false;
        }
    }

    // -------------------------------------------------------------------------
    // Protected helpers
    // -------------------------------------------------------------------------

    protected function makeStub(string $fqcn): array
    {
        try {
            $ref         = new ReflectionClass($fqcn);
            $isInterface = $ref->isInterface();
            $isTrait     = $ref->isTrait();
            $isAbstract  = $ref->isAbstract() && ! $isInterface;
            $isEloquent  = $ref->isSubclassOf('Illuminate\Database\Eloquent\Model');
        } catch (\Throwable) {
            $isInterface = $isTrait = $isAbstract = $isEloquent = false;
        }

        $parts = explode('\\', $fqcn);

        return [
            'fqcn'         => $fqcn,
            'name'         => end($parts),
            'namespace'    => implode('\\', array_slice($parts, 0, -1)),
            'isAbstract'   => $isAbstract,
            'isInterface'  => $isInterface,
            'isTrait'      => $isTrait,
            'isEnum'       => false,
            'isEloquent'   => $isEloquent,
            'isVendor'     => $this->isVendorClass($fqcn),
            'isStub'       => true,
            'parent'       => null,
            'interfaces'   => [],
            'traits'       => [],
            'relations'    => [],
            'dependencies' => [],
            'properties'   => [],
            'methods'      => [],
        ];
    }

    /**
     * Collect dependency FQCNs via Reflection by inspecting all type hints declared
     * in the class's own properties, method parameters, and method return types.
     *
     * FQCNs already represented as parent, interface, or trait are excluded,
     * as are built-in types (int, string, bool, …) and the class itself.
     *
     * @param  string[]  $excludedFqcns  FQCNs already modelled as structural relations
     * @return string[]
     */
    protected function extractDependencies(ReflectionClass $ref, array $excludedFqcns): array
    {
        $classFile = $ref->getFileName();
        $excluded  = array_merge($excludedFqcns, [$ref->getName()]);
        $collected = [];

        // Properties declared in this class
        foreach ($ref->getProperties() as $prop) {
            if ($prop->getDeclaringClass()->getName() !== $ref->getName()) {
                continue;
            }
            $this->collectFromType($prop->getType(), $excluded, $collected);
        }

        // Methods declared in this class
        foreach ($ref->getMethods() as $method) {
            if ($method->getFileName() !== $classFile) {
                continue;
            }
            $this->collectFromType($method->getReturnType(), $excluded, $collected);
        }

        return array_values(array_unique($collected));
    }

    /**
     * Extract class/interface FQCNs from a ReflectionType (named, union, or intersection)
     * and append non-built-in, non-excluded ones to $collected.
     *
     * @param  string[]  $excluded
     * @param  string[]  &$collected
     */
    protected function collectFromType(mixed $type, array $excluded, array &$collected): void
    {
        if ($type === null) {
            return;
        }

        $named = match (true) {
            $type instanceof ReflectionNamedType        => [$type],
            $type instanceof ReflectionUnionType        => $type->getTypes(),
            $type instanceof ReflectionIntersectionType => $type->getTypes(),
            default                                     => [],
        };

        foreach ($named as $t) {
            if (! ($t instanceof ReflectionNamedType) || $t->isBuiltin()) {
                continue;
            }

            $fqcn = $t->getName();

            if (in_array($fqcn, $excluded, true)) {
                continue;
            }

            $collected[] = $fqcn;
        }
    }

    /**
     * Extract Eloquent model properties from $fillable, $casts, $hidden, $dates, $appends.
     *
     * Type resolution priority: $casts → $dates → 'mixed'.
     * Visibility: $fillable → public (or private if also $hidden),
     *             $hidden → private, $dates / $appends → public.
     */
    protected function extractEloquentProperties(ReflectionClass $ref): array
    {
        try {
            $instance = $ref->newInstanceWithoutConstructor();
        } catch (\Throwable) {
            $instance = null;
        }

        $fillable = $this->readArrayProp($ref, $instance, 'fillable');
        $hidden   = $this->readArrayProp($ref, $instance, 'hidden');
        $dates    = $this->readArrayProp($ref, $instance, 'dates');
        $appends  = $this->readArrayProp($ref, $instance, 'appends');
        $casts    = $this->readArrayProp($ref, $instance, 'casts');

        $hiddenSet = array_flip($hidden);
        $datesSet  = array_flip($dates);

        $properties = [];
        $seen       = [];

        $add = function (string $name, string $visibility) use (&$properties, &$seen, $casts, $datesSet): void {
            if (isset($seen[$name])) {
                return;
            }
            $seen[$name] = true;

            $type = match (true) {
                isset($casts[$name])    => $this->normalizeCastType($casts[$name]),
                isset($datesSet[$name]) => 'datetime',
                default                 => 'mixed',
            };

            $properties[] = [
                'name'       => $name,
                'type'       => $type,
                'visibility' => $visibility,
                'static'     => false,
                'source'     => 'eloquent',
            ];
        };

        foreach ($fillable as $field) {
            $add($field, isset($hiddenSet[$field]) ? 'private' : 'public');
        }
        foreach ($hidden as $field) {
            $add($field, 'private');
        }
        foreach ($dates as $field) {
            $add($field, 'public');
        }
        foreach ($appends as $field) {
            $add($field, 'public');
        }
        foreach (array_keys($casts) as $field) {
            $add($field, isset($hiddenSet[$field]) ? 'private' : 'public');
        }

        return $properties;
    }

    /**
     * Read a protected/private array property from a model instance,
     * walking up the class hierarchy as needed.
     */
    protected function readArrayProp(ReflectionClass $ref, ?object $instance, string $propName): array
    {
        $declaring = $ref;
        while ($declaring !== false) {
            if ($declaring->hasProperty($propName)) {
                $prop = $declaring->getProperty($propName);
                $prop->setAccessible(true);

                $value = $instance !== null
                    ? $prop->getValue($instance)
                    : ($prop->hasDefaultValue() ? $prop->getDefaultValue() : []);

                return is_array($value) ? $value : [];
            }
            $declaring = $declaring->getParentClass();
        }

        return [];
    }

    /**
     * Normalize an Eloquent cast type to a readable label.
     * Class-based casts (e.g. AsCollection::class) return the short name.
     * Parameterized casts (e.g. "decimal:2") return the base type.
     */
    protected function normalizeCastType(string $cast): string
    {
        if (str_contains($cast, '\\')) {
            $parts = explode('\\', $cast);
            return end($parts);
        }

        return explode(':', $cast)[0];
    }

    protected function extractProperties(ReflectionClass $ref): array
    {
        $properties = [];

        foreach ($ref->getProperties() as $prop) {
            $declaring = $prop->getDeclaringClass();

            // Skip properties inherited from a parent class or provided by a trait
            if ($ref->getName() !== $declaring->getName() || $declaring->isTrait()) {
                continue;
            }

            $properties[] = [
                'name'       => $prop->getName(),
                'type'       => $this->resolveType($prop->getType()),
                'visibility' => $this->visibility($prop),
                'static'     => $prop->isStatic(),
            ];
        }

        return $properties;
    }

    protected function extractMethods(ReflectionClass $ref): array
    {
        $classFile = $ref->getFileName();
        $methods   = [];

        foreach ($ref->getMethods() as $method) {
            // Skip methods inherited from a parent or provided by a trait
            if ($method->getFileName() !== $classFile) {
                continue;
            }

            $params = array_map(function ($param) {
                $type = $this->resolveType($param->getType());
                return ($type ? "{$type} " : '') . '$' . $param->getName();
            }, $method->getParameters());

            $methods[] = [
                'name'       => $method->getName(),
                'visibility' => $this->visibility($method),
                'static'     => $method->isStatic(),
                'abstract'   => $method->isAbstract(),
                'return'     => $this->resolveType($method->getReturnType()),
                'params'     => $params,
            ];
        }

        return $methods;
    }

    protected function visibility(ReflectionProperty|ReflectionMethod $item): string
    {
        return match (true) {
            $item->isPublic()    => 'public',
            $item->isProtected() => 'protected',
            default              => 'private',
        };
    }

    protected function resolveType(mixed $type): ?string
    {
        if ($type === null) {
            return null;
        }

        if ($type instanceof ReflectionUnionType) {
            return implode('|', array_map(fn ($t) => $t->getName(), $type->getTypes()));
        }

        if ($type instanceof ReflectionNamedType) {
            $name = $type->getName();
            return $type->allowsNull() && $name !== 'null' ? "?{$name}" : $name;
        }

        return (string) $type;
    }

    protected function getComposerClassMap(): array
    {
        $autoloadFile = base_path('vendor/composer/autoload_classmap.php');

        return file_exists($autoloadFile) ? require $autoloadFile : [];
    }
}
