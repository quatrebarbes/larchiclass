<?php

namespace Quatrebarbes\Larchiclass\Analyzers;

use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use ReflectionNamedType;
use ReflectionUnionType;
use Symfony\Component\Finder\Finder;

class ClassAnalyzer
{
    public function __construct(
        protected readonly RelationshipExtractor $relationshipExtractor = new RelationshipExtractor()
    ) {}

    /**
     * Discover all FQCNs in a given namespace by scanning Composer's classmap,
     * with a filesystem fallback (PSR-4 convention).
     */
    public function discoverClasses(string $namespace): array
    {
        $namespace = rtrim($namespace, '\\');

        $classes = array_filter(
            array_keys($this->getComposerClassMap()),
            fn (string $fqcn) => str_starts_with($fqcn, $namespace . '\\') || $fqcn === $namespace
        );

        return array_values($classes);
    }

    /**
     * Analyze a single class via Reflection and return structured data.
     */
    public function analyze(string $fqcn, bool $withVendor = false, bool $isEloquentModel = false): array
    {
        return $this->buildClassData(new ReflectionClass($fqcn), $fqcn, $withVendor, $isEloquentModel);
    }

    // -------------------------------------------------------------------------
    // Core data builder
    // -------------------------------------------------------------------------

    protected function buildClassData(ReflectionClass $ref, string $fqcn, bool $withVendor, bool $isEloquentModel): array
    {
        $parentFqcn = $ref->getParentClass() ? $ref->getParentClass()->getName() : null;

        $isModel   = $isEloquentModel && $this->isEloquentModel($ref);
        $relations = $isModel ? $this->relationshipExtractor->extract($fqcn) : [];

        return [
            'fqcn'            => $fqcn,
            'name'            => $ref->getShortName(),
            'namespace'       => $ref->getNamespaceName(),
            'isAbstract'      => $ref->isAbstract() && ! $ref->isInterface(),
            'isInterface'     => $ref->isInterface(),
            'isTrait'         => $ref->isTrait(),
            'isEnum'          => $ref->isEnum(),
            'isEloquent'      => $isModel,
            'isVendor'        => false,
            'parent'          => $parentFqcn,
            'parentIsVendor'  => $parentFqcn !== null && $this->isVendorClass($parentFqcn),
            'interfaces'      => array_values($ref->getInterfaceNames()),
            'traits'          => array_values($ref->getTraitNames()),
            'withVendor'      => $withVendor,
            'relations'       => $relations,
            'properties'      => $isModel
                ? $this->extractEloquentProperties($ref)
                : $this->extractProperties($ref),
            'methods'         => array_filter(
                $this->extractMethods($ref),
                fn($method) => !in_array($method['name'], array_column($relations, 'method'))
            ),
        ];
    }

    /**
     * Build stub entries for vendor classes referenced as parents / interfaces / traits.
     */
    public function buildVendorStubs(array $classDataList): array
    {
        $knownFqcn = array_column($classDataList, 'fqcn');
        $stubs     = [];
        $seen      = [];

        foreach ($classDataList as $class) {
            $refs = array_filter(array_merge(
                $class['parent'] ? [$class['parent']] : [],
                $class['interfaces'],
                $class['traits'],
            ));

            foreach ($refs as $fqcn) {
                if (in_array($fqcn, $knownFqcn, true) || isset($seen[$fqcn])) {
                    continue;
                }

                if (! $this->isVendorClass($fqcn)) {
                    continue;
                }

                $seen[$fqcn] = true;

                if ($class['withVendor']) {
                    try {
                        $analyzed             = $this->analyze($fqcn, true);
                        $analyzed['isVendor'] = true;
                        $stubs[]              = $analyzed;
                    } catch (\Throwable) {
                        $stubs[] = $this->makeStub($fqcn);
                    }
                } else {
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
            $file = (new ReflectionClass($fqcn))->getFileName();
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
        } catch (\Throwable) {
            $isInterface = $isTrait = $isAbstract = false;
        }

        $parts = explode('\\', $fqcn);

        return [
            'fqcn'            => $fqcn,
            'name'            => end($parts),
            'namespace'       => implode('\\', array_slice($parts, 0, -1)),
            'isAbstract'      => $isAbstract,
            'isInterface'     => $isInterface,
            'isTrait'         => $isTrait,
            'isEnum'          => false,
            'isVendor'        => true,
            'isStub'          => true,
            'parent'          => null,
            'parentIsVendor'  => false,
            'interfaces'      => [],
            'traits'          => [],
            'withVendor'      => false,
            'relations'       => [],
            'properties'      => [],
            'methods'         => [],
        ];
    }

    protected function isEloquentModel(ReflectionClass $ref): bool
    {
        return $ref->isSubclassOf('Illuminate\Database\Eloquent\Model');
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
                'visibility' => $this->methodVisibility($method),
                'static'     => $method->isStatic(),
                'abstract'   => $method->isAbstract(),
                'return'     => $this->resolveType($method->getReturnType()),
                'params'     => $params,
            ];
        }

        return $methods;
    }

    protected function visibility(ReflectionProperty $prop): string
    {
        return match (true) {
            $prop->isPublic()    => 'public',
            $prop->isProtected() => 'protected',
            default              => 'private',
        };
    }

    protected function methodVisibility(ReflectionMethod $method): string
    {
        return match (true) {
            $method->isPublic()    => 'public',
            $method->isProtected() => 'protected',
            default                => 'private',
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
