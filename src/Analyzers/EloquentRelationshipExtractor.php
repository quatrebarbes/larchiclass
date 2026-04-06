<?php

namespace Quatrebarbes\Larchiclass\Analyzers;

use ReflectionClass;
use ReflectionMethod;

/**
 * Extracts Eloquent relationship definitions from a model class.
 *
 * Detection strategy (in priority order):
 *   1. Return type hint  → maps Eloquent relation class to relation kind
 *   2. Method body scan  → regex on $this->hasMany(...) / $this->belongsTo(...) etc.
 *
 * For each detected relationship we record:
 *   - kind        : hasOne | hasMany | belongsTo | belongsToMany |
 *                   hasManyThrough | hasOneThrough |
 *                   morphOne | morphMany | morphTo | morphToMany | morphedByMany
 *   - method      : the PHP method name (e.g. "posts")
 *   - related     : short class name of the related model (e.g. "Post")
 *   - relatedFqcn : fully qualified class name when resolvable, otherwise null
 */
class EloquentRelationshipExtractor
{
    /**
     * Maps Eloquent relation return-type short names → canonical kind label.
     */
    protected const RETURN_TYPE_MAP = [
        // hasOne / hasMany
        'HasOne'           => 'hasOne',
        'HasMany'          => 'hasMany',
        // belongsTo / belongsToMany
        'BelongsTo'        => 'belongsTo',
        'BelongsToMany'    => 'belongsToMany',
        // through
        'HasOneThrough'    => 'hasOneThrough',
        'HasManyThrough'   => 'hasManyThrough',
        // morph
        'MorphOne'         => 'morphOne',
        'MorphMany'        => 'morphMany',
        'MorphTo'          => 'morphTo',
        'MorphToMany'      => 'morphToMany',
        'MorphedByMany'    => 'morphedByMany',
    ];

    /**
     * Maps $this->xxx() call names → canonical kind label (body-scan fallback).
     */
    protected const BODY_CALL_MAP = [
        'hasOne'         => 'hasOne',
        'hasMany'        => 'hasMany',
        'belongsTo'      => 'belongsTo',
        'belongsToMany'  => 'belongsToMany',
        'hasOneThrough'  => 'hasOneThrough',
        'hasManyThrough' => 'hasManyThrough',
        'morphOne'       => 'morphOne',
        'morphMany'      => 'morphMany',
        'morphTo'        => 'morphTo',
        'morphToMany'    => 'morphToMany',
        'morphedByMany'  => 'morphedByMany',
    ];

    /**
     * Extract all relationships declared directly on $fqcn (not inherited).
     *
     * @return array<int, array{kind: string, method: string, related: string, relatedFqcn: string|null}>
     */
    public function extract(string $fqcn): array
    {
        $ref           = new ReflectionClass($fqcn);
        $relationships = [];

        foreach ($ref->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            // Only methods declared on this class, not inherited
            if ($method->getDeclaringClass()->getName() !== $fqcn) {
                continue;
            }

            // Skip methods with required parameters - relation methods have none
            if ($method->getNumberOfRequiredParameters() > 0) {
                continue;
            }

            $relationship = $this->detectByReturnType($method, $ref)
                         ?? $this->detectByBodyScan($method, $ref);

            if ($relationship !== null) {
                $relationships[] = $relationship;
            }
        }

        return $relationships;
    }

    // -----------------------------------------------------------------------
    // Strategy 1 - return type hint
    // -----------------------------------------------------------------------

    protected function detectByReturnType(ReflectionMethod $method, ReflectionClass $classRef): ?array
    {
        $returnType = $method->getReturnType();
        if ($returnType === null) {
            return null;
        }

        // Handle union types - pick the first named type that matches
        $typeNames = [];
        if ($returnType instanceof \ReflectionUnionType) {
            foreach ($returnType->getTypes() as $t) {
                $typeNames[] = $t->getName();
            }
        } elseif ($returnType instanceof \ReflectionNamedType) {
            $typeNames[] = $returnType->getName();
        }

        foreach ($typeNames as $typeName) {
            $shortType = $this->shortName($typeName);
            if (! isset(self::RETURN_TYPE_MAP[$shortType])) {
                continue;
            }

            $kind = self::RETURN_TYPE_MAP[$shortType];

            // morphTo has no related model extractable from the return type
            if ($kind === 'morphTo') {
                return [
                    'kind'        => $kind,
                    'method'      => $method->getName(),
                    'related'     => '*',
                    'relatedFqcn' => null,
                ];
            }

            // Try body scan to resolve related model even when return type confirms it's a relation
            $related = $this->extractRelatedFromBody($method, $classRef);

            if ($related === null) {
                return null; // can't determine target - skip
            }

            return [
                'kind'        => $kind,
                'method'      => $method->getName(),
                'related'     => $related['short'],
                'relatedFqcn' => $related['fqcn'],
            ];
        }

        return null;
    }

    // -----------------------------------------------------------------------
    // Strategy 2 - method body scan
    // -----------------------------------------------------------------------

    protected function detectByBodyScan(ReflectionMethod $method, ReflectionClass $classRef): ?array
    {
        $body = $this->readMethodBody($method);
        if ($body === null) {
            return null;
        }

        $callPattern = implode('|', array_keys(self::BODY_CALL_MAP));

        // Match: $this->hasMany(Foo::class, ...) or $this->hasMany('App\Models\Foo', ...)
        if (! preg_match(
            '/\$this\s*->\s*(' . $callPattern . ')\s*\(/',
            $body,
            $callMatch
        )) {
            return null;
        }

        $kind = self::BODY_CALL_MAP[$callMatch[1]];

        if ($kind === 'morphTo') {
            return [
                'kind'        => $kind,
                'method'      => $method->getName(),
                'related'     => '*',
                'relatedFqcn' => null,
            ];
        }

        $related = $this->extractRelatedFromBody($method, $classRef);
        if ($related === null) {
            return null;
        }

        return [
            'kind'        => $kind,
            'method'      => $method->getName(),
            'related'     => $related['short'],
            'relatedFqcn' => $related['fqcn'],
        ];
    }

    // -----------------------------------------------------------------------
    // Related-model extraction from method body
    // -----------------------------------------------------------------------

    protected function extractRelatedFromBody(ReflectionMethod $method, ReflectionClass $classRef): ?array
    {
        $body = $this->readMethodBody($method);
        if ($body === null) {
            return null;
        }

        // Pattern 1 - Foo::class
        if (preg_match('/(\w+)::class/', $body, $m)) {
            $shortName = $m[1];
            return [
                'short' => $shortName,
                'fqcn'  => $this->resolveClass($shortName, $classRef),
            ];
        }

        // Pattern 2 - string literal 'App\Models\Foo' or "App\Models\Foo"
        if (preg_match('/[\'"]([A-Za-z\\\\]+)[\'"]/', $body, $m)) {
            $raw   = $m[1];
            $short = $this->shortName($raw);
            $fqcn  = class_exists($raw) ? $raw : $this->resolveClass($short, $classRef);
            return [
                'short' => $short,
                'fqcn'  => $fqcn,
            ];
        }

        return null;
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * Read the source of a method body (between the first { and matching }).
     * Returns null if the file is not readable or the method is native.
     */
    protected function readMethodBody(ReflectionMethod $method): ?string
    {
        $file = $method->getFileName();
        if ($file === false || ! is_readable($file)) {
            return null;
        }

        $start = $method->getStartLine();
        $end   = $method->getEndLine();

        if ($start === false || $end === false) {
            return null;
        }

        $lines = file($file);
        if ($lines === false) {
            return null;
        }

        return implode('', array_slice($lines, $start - 1, $end - $start + 1));
    }

    /**
     * Try to resolve a short class name to a FQCN using:
     *   1. The namespace of the declaring class
     *   2. The use statements in the source file
     */
    protected function resolveClass(string $shortName, ReflectionClass $classRef): ?string
    {
        // Try same namespace first (most common for models)
        $candidate = $classRef->getNamespaceName() . '\\' . $shortName;
        if (class_exists($candidate)) {
            return $candidate;
        }

        // Parse use statements from the file
        $file = $classRef->getFileName();
        if ($file === false || ! is_readable($file)) {
            return null;
        }

        $source = file_get_contents($file);
        if ($source === false) {
            return null;
        }

        // Match: use Some\Namespace\ClassName; or use Some\Namespace\ClassName as Alias;
        preg_match_all(
            '/^use\s+([\w\\\\]+)(?:\s+as\s+(\w+))?\s*;/m',
            $source,
            $uses,
            PREG_SET_ORDER
        );

        foreach ($uses as $use) {
            $fqcn  = $use[1];
            $alias = $use[2] ?? $this->shortName($fqcn);

            if ($alias === $shortName) {
                return $fqcn;
            }
        }

        return null;
    }

    protected function shortName(string $fqcn): string
    {
        $parts = explode('\\', $fqcn);
        return end($parts);
    }
}
