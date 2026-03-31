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
        private readonly RelationshipExtractor $relationshipExtractor = new RelationshipExtractor()
    ) {}
    /**
     * Discover all FQCN in a given namespace by scanning Composer's classmap.
     */
    public function discoverClasses(string $namespace): array
    {
        $namespace = rtrim($namespace, '\\') . '\\';
        $classes   = [];

        // Strategy 1: Composer classmap (most reliable)
        $classMap = $this->getComposerClassMap();
        foreach (array_keys($classMap) as $fqcn) {
            if (str_starts_with($fqcn, $namespace)) {
                $classes[] = $fqcn;
            }
        }

        // Strategy 2: Filesystem scan as fallback (PSR-4 convention)
        if (empty($classes)) {
            $classes = $this->discoverByFilesystem($namespace);
        }

        return array_values(array_unique($classes));
    }

    /**
     * Analyze a single class via Reflection and return structured data.
     *
     * When $withVendor is false (default), vendor classes referenced as parent /
     * interfaces / traits are recorded as stubs (name only, no members).
     * When $withVendor is true, they are fully expanded.
     */
    public function analyze(string $fqcn, bool $withVendor = false): array
    {
        $ref = new ReflectionClass($fqcn);

        $parentFqcn    = $ref->getParentClass() ? $ref->getParentClass()->getName() : null;
        $interfaceFqcn = array_values($ref->getInterfaceNames());
        $traitFqcn     = array_values($ref->getTraitNames());

        $isModel       = $this->isEloquentModel($ref);
        $relations     = $isModel ? $this->relationshipExtractor->extract($fqcn) : [];
        $relationMethods = array_column($relations, 'method');

        return [
            'fqcn'           => $fqcn,
            'name'           => $ref->getShortName(),
            'namespace'      => $ref->getNamespaceName(),
            'isAbstract'     => $ref->isAbstract() && ! $ref->isInterface(),
            'isInterface'    => $ref->isInterface(),
            'isTrait'        => $ref->isTrait(),
            'isEnum'         => $ref->isEnum(),
            'isEloquent'     => $isModel,
            'isVendor'       => false,
            'parent'         => $parentFqcn,
            'parentIsVendor' => $parentFqcn !== null && $this->isVendorClass($parentFqcn),
            'interfaces'     => $interfaceFqcn,
            'traits'         => $traitFqcn,
            'withVendor'     => $withVendor,
            'relations'      => $relations,
            'properties'     => $isModel
                                    ? $this->extractEloquentProperties($ref)
                                    : $this->extractProperties($ref),
            // Relation methods are stored separately; they will be filtered
            // from the methods list unless --keep-relation-methods is passed.
            // We attach the relation method names so the generator can decide.
            'relationMethods' => $relationMethods,
            'methods'         => $this->extractMethods($ref),
        ];
    }

    /**
     * Build stub entries for vendor classes that appear as parents / interfaces /
     * traits of analyzed classes - only when $withVendor is false.
     * These stubs are rendered as empty boxes with a <<vendor>> stereotype.
     */
    public function buildVendorStubs(array $classDataList): array
    {
        $knownFqcn = array_column($classDataList, 'fqcn');
        $stubs     = [];
        $seen      = [];

        foreach ($classDataList as $class) {
            $withVendor = $class['withVendor'];

            // Collect all referenced external FQCNs
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

                if ($withVendor) {
                    // Full analysis of the vendor class
                    try {
                        $analyzed            = $this->analyze($fqcn, true);
                        $analyzed['isVendor'] = true;
                        $stubs[]             = $analyzed;
                    } catch (\Throwable) {
                        $stubs[] = $this->makeStub($fqcn);
                    }
                } else {
                    // Lightweight stub - name only
                    $stubs[] = $this->makeStub($fqcn);
                }
            }
        }

        return $stubs;
    }

    // -----------------------------------------------------------------------
    // Public helper
    // -----------------------------------------------------------------------

    public function isVendorClass(string $fqcn): bool
    {
        $classMap = $this->getComposerClassMap();

        if (isset($classMap[$fqcn])) {
            $path = str_replace('\\', '/', $classMap[$fqcn]);
            return str_contains($path, '/vendor/');
        }

        // Fallback: try resolving via Reflection
        try {
            $file = (new ReflectionClass($fqcn))->getFileName();
            return $file !== false && str_contains(str_replace('\\', '/', $file), '/vendor/');
        } catch (\Throwable) {
            return false;
        }
    }

    // -----------------------------------------------------------------------
    // Private helpers
    // -----------------------------------------------------------------------

    private function makeStub(string $fqcn): array
    {
        try {
            $ref         = new ReflectionClass($fqcn);
            $isInterface = $ref->isInterface();
            $isTrait     = $ref->isTrait();
            $isAbstract  = $ref->isAbstract() && ! $isInterface;
        } catch (\Throwable) {
            $isInterface = false;
            $isTrait     = false;
            $isAbstract  = false;
        }

        $parts = explode('\\', $fqcn);

        return [
            'fqcn'           => $fqcn,
            'name'           => end($parts),
            'namespace'      => implode('\\', array_slice($parts, 0, -1)),
            'isAbstract'     => $isAbstract,
            'isInterface'    => $isInterface,
            'isTrait'        => $isTrait,
            'isEnum'         => false,
            'isVendor'       => true,
            'isStub'         => true,   // no members rendered
            'parent'         => null,
            'parentIsVendor' => false,
            'interfaces'     => [],
            'traits'         => [],
            'withVendor'     => false,
            'relations'      => [],
            'relationMethods' => [],
            'properties'     => [],
            'methods'        => [],
        ];
    }

    private function isEloquentModel(ReflectionClass $ref): bool
    {
        return $ref->isSubclassOf('Illuminate\Database\Eloquent\Model');
    }

    /**
     * Extract properties from an Eloquent model by reading the Eloquent
     * metadata arrays: $fillable, $casts, $hidden, $dates, $appends.
     *
     * Strategy: instantiate the class without calling the constructor
     * (ReflectionClass::newInstanceWithoutConstructor) so we can safely read
     * the default property values even in environments without a database.
     *
     * Type resolution priority:
     *   1. $casts   → explicit cast type (e.g. 'integer', 'boolean', 'datetime')
     *   2. $dates   → 'datetime'
     *   3. otherwise → 'mixed'
     *
     * Visibility:
     *   - $fillable  → public  (mass-assignable)
     *   - $hidden    → private (excluded from serialization)
     *   - $appends   → public  (virtual / accessor attributes)
     *   - $dates     → public
     */
    private function extractEloquentProperties(ReflectionClass $ref): array
    {
        // Safe instantiation - no constructor, no DB calls
        try {
            $instance = $ref->newInstanceWithoutConstructor();
        } catch (\Throwable) {
            $instance = null;
        }

        $fillable = $this->readArrayProp($ref, $instance, 'fillable');
        $hidden   = $this->readArrayProp($ref, $instance, 'hidden');
        $dates    = $this->readArrayProp($ref, $instance, 'dates');
        $appends  = $this->readArrayProp($ref, $instance, 'appends');
        $casts    = $this->readArrayProp($ref, $instance, 'casts');   // ['field' => 'type']

        // Index hidden and dates for O(1) lookup
        $hiddenSet = array_flip($hidden);
        $datesSet  = array_flip($dates);

        $properties = [];
        $seen       = [];

        $add = function (string $name, string $visibility) use (
            &$properties, &$seen, $casts, $datesSet
        ): void {
            if (isset($seen[$name])) {
                return;
            }
            $seen[$name] = true;

            $type = match (true) {
                isset($casts[$name])  => $this->normalizeCastType($casts[$name]),
                isset($datesSet[$name]) => 'datetime',
                default               => 'mixed',
            };

            $properties[] = [
                'name'       => $name,
                'type'       => $type,
                'visibility' => $visibility,
                'static'     => false,
                'source'     => 'eloquent',
            ];
        };

        // $fillable → public (unless also in $hidden)
        foreach ($fillable as $field) {
            $add($field, isset($hiddenSet[$field]) ? 'private' : 'public');
        }

        // $hidden fields not already in $fillable → private
        foreach ($hidden as $field) {
            $add($field, 'private');
        }

        // $dates fields not yet seen → public datetime
        foreach ($dates as $field) {
            $add($field, 'public');
        }

        // $appends (virtual accessor attributes) → public
        foreach ($appends as $field) {
            $add($field, 'public');
        }

        return $properties;
    }

    /**
     * Read a protected/private array property from a model instance (or its
     * default value) without triggering Eloquent boot logic.
     */
    private function readArrayProp(ReflectionClass $ref, ?object $instance, string $propName): array
    {
        // Walk up the class hierarchy to find where the property is declared
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
     * Normalize Eloquent cast types to more readable labels.
     * Handles class-based casts (e.g. AsCollection::class) by using the short name.
     */
    private function normalizeCastType(string $cast): string
    {
        // Class-based cast (FQCN or short name with backslash)
        if (str_contains($cast, '\\')) {
            $parts = explode('\\', $cast);
            return end($parts);
        }

        // Parameterized cast: e.g. "decimal:2" → "decimal"
        return explode(':', $cast)[0];
    }

    private function extractProperties(ReflectionClass $ref): array
    {
        $properties = [];

        foreach ($ref->getProperties() as $prop) {
            // Only own properties, not inherited ones
            if ($prop->getDeclaringClass()->getName() !== $ref->getName()) {
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

    private function extractMethods(ReflectionClass $ref): array
    {
        $methods = [];

        foreach ($ref->getMethods() as $method) {
            if ($method->getDeclaringClass()->getName() !== $ref->getName()) {
                continue;
            }

            $params = [];
            foreach ($method->getParameters() as $param) {
                $type     = $this->resolveType($param->getType());
                $params[] = ($type ? "{$type} " : '') . '$' . $param->getName();
            }

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

    private function visibility(ReflectionProperty $prop): string
    {
        if ($prop->isPublic()) return 'public';
        if ($prop->isProtected()) return 'protected';
        return 'private';
    }

    private function methodVisibility(ReflectionMethod $method): string
    {
        if ($method->isPublic()) return 'public';
        if ($method->isProtected()) return 'protected';
        return 'private';
    }

    private function resolveType(mixed $type): ?string
    {
        if ($type === null) {
            return null;
        }

        if ($type instanceof ReflectionUnionType) {
            return implode('|', array_map(
                fn ($t) => $t->getName(),
                $type->getTypes()
            ));
        }

        if ($type instanceof ReflectionNamedType) {
            $name = $type->getName();
            return $type->allowsNull() && $name !== 'null' ? "?{$name}" : $name;
        }

        return (string) $type;
    }

    private function getComposerClassMap(): array
    {
        $autoloadFile = base_path('vendor/composer/autoload_classmap.php');
        if (file_exists($autoloadFile)) {
            return require $autoloadFile;
        }
        return [];
    }

    private function discoverByFilesystem(string $namespace): array
    {
        $classes = [];

        // Resolve namespace to directory using PSR-4 mappings from composer.json
        $composerJson = base_path('composer.json');
        if (! file_exists($composerJson)) {
            return [];
        }

        $composer  = json_decode(file_get_contents($composerJson), true);
        $psr4      = array_merge(
            $composer['autoload']['psr-4'] ?? [],
            $composer['autoload-dev']['psr-4'] ?? []
        );

        $directory = null;
        $bestMatch = '';

        foreach ($psr4 as $prefix => $path) {
            if (str_starts_with($namespace, $prefix) && strlen($prefix) > strlen($bestMatch)) {
                $bestMatch = $prefix;
                $subPath   = str_replace('\\', '/', substr($namespace, strlen($prefix)));
                $directory = base_path(rtrim($path, '/') . '/' . $subPath);
            }
        }

        if (! $directory || ! is_dir($directory)) {
            return [];
        }

        $finder = new Finder();
        $finder->files()->in($directory)->name('*.php');

        foreach ($finder as $file) {
            $relative = str_replace('/', '\\', $file->getRelativePathname());
            $fqcn     = $namespace . str_replace('.php', '', $relative);

            if (class_exists($fqcn) || interface_exists($fqcn) || trait_exists($fqcn)) {
                $classes[] = $fqcn;
            }
        }

        return $classes;
    }
}
