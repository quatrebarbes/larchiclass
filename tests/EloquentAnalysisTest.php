<?php

namespace Quatrebarbes\Larchiclass\Tests;

use Orchestra\Testbench\TestCase;
use Quatrebarbes\Larchiclass\Analyzers\ClassAnalyzer;
use Quatrebarbes\Larchiclass\LarchiServiceProvider;
use Quatrebarbes\Larchiclass\Tests\Fixtures\Post;
use Quatrebarbes\Larchiclass\Tests\Fixtures\User;

class EloquentAnalysisTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [LarchiServiceProvider::class];
    }

    private function analyzer(): ClassAnalyzer
    {
        return new ClassAnalyzer();
    }

    // -------------------------------------------------------------------------
    // isEloquent flag
    // -------------------------------------------------------------------------

    public function test_eloquent_model_is_flagged_when_mode_enabled(): void
    {
        $data = $this->analyzer()->readTargetedClasses(User::class, isEloquentModel: true);

        $this->assertTrue($data['isEloquent']);
    }

    public function test_eloquent_model_not_flagged_when_mode_disabled(): void
    {
        $data = $this->analyzer()->readTargetedClasses(User::class, isEloquentModel: false);

        $this->assertFalse($data['isEloquent']);
        $this->assertEmpty($data['relations']);
    }

    // -------------------------------------------------------------------------
    // Eloquent properties — User ($fillable, $hidden, $appends, $casts)
    // -------------------------------------------------------------------------

    public function test_fillable_fields_are_public(): void
    {
        $data  = $this->analyzer()->readTargetedClasses(User::class, isEloquentModel: true);
        $props = array_column($data['properties'], 'visibility', 'name');

        $this->assertSame('public', $props['name']);
        $this->assertSame('public', $props['email']);
    }

    public function test_hidden_field_in_fillable_becomes_private(): void
    {
        $data  = $this->analyzer()->readTargetedClasses(User::class, isEloquentModel: true);
        $props = array_column($data['properties'], 'visibility', 'name');

        // 'password' is in both $fillable and $hidden
        $this->assertSame('private', $props['password']);
    }

    public function test_appends_fields_are_public(): void
    {
        $data  = $this->analyzer()->readTargetedClasses(User::class, isEloquentModel: true);
        $props = array_column($data['properties'], 'visibility', 'name');

        $this->assertSame('public', $props['full_name']);
    }

    public function test_cast_type_resolved_for_boolean(): void
    {
        $data  = $this->analyzer()->readTargetedClasses(User::class, isEloquentModel: true);
        $props = array_column($data['properties'], 'type', 'name');

        $this->assertSame('boolean', $props['is_admin']);
    }

    public function test_fields_are_not_duplicated(): void
    {
        $data  = $this->analyzer()->readTargetedClasses(User::class, isEloquentModel: true);
        $names = array_column($data['properties'], 'name');

        // 'password' appears in $fillable and $hidden — must appear only once
        $this->assertSame(1, count(array_filter($names, fn ($n) => $n === 'password')));
    }

    public function test_source_is_eloquent(): void
    {
        $data = $this->analyzer()->readTargetedClasses(User::class, isEloquentModel: true);

        foreach ($data['properties'] as $prop) {
            $this->assertSame('eloquent', $prop['source']);
        }
    }

    public function test_field_with_no_cast_and_not_in_dates_gets_mixed_type(): void
    {
        // 'name' and 'email' have no cast and are not in $dates
        $data  = $this->analyzer()->readTargetedClasses(User::class, isEloquentModel: true);
        $props = array_column($data['properties'], 'type', 'name');

        $this->assertSame('mixed', $props['name']);
        $this->assertSame('mixed', $props['email']);
    }

    // -------------------------------------------------------------------------
    // Eloquent properties — Post ($fillable, $hidden, $casts)
    // -------------------------------------------------------------------------

    public function test_post_hidden_field_not_in_fillable_is_private(): void
    {
        $data  = $this->analyzer()->readTargetedClasses(Post::class, isEloquentModel: true);
        $props = array_column($data['properties'], 'visibility', 'name');

        // 'body' is in $fillable AND $hidden
        $this->assertSame('private', $props['body']);
    }

    public function test_post_cast_type_datetime(): void
    {
        $data  = $this->analyzer()->readTargetedClasses(Post::class, isEloquentModel: true);
        $props = array_column($data['properties'], 'type', 'name');

        $this->assertSame('datetime', $props['published_at']);
        $this->assertSame('integer',  $props['views']);
    }

    // -------------------------------------------------------------------------
    // normalizeCastType edge cases
    // -------------------------------------------------------------------------

    public function test_parameterized_cast_returns_base_type(): void
    {
        // We test normalizeCastType indirectly by adding a fixture with a decimal cast.
        // Alternatively, we access the protected method via a subclass.
        $analyzer = new class extends ClassAnalyzer {
            public function publicNormalizeCast(string $cast): string
            {
                return $this->normalizeCastType($cast);
            }
        };

        $this->assertSame('decimal', $analyzer->publicNormalizeCast('decimal:2'));
        $this->assertSame('decimal', $analyzer->publicNormalizeCast('decimal:4'));
    }

    public function test_class_based_cast_returns_short_name(): void
    {
        $analyzer = new class extends ClassAnalyzer {
            public function publicNormalizeCast(string $cast): string
            {
                return $this->normalizeCastType($cast);
            }
        };

        $this->assertSame('AsCollection', $analyzer->publicNormalizeCast('Illuminate\Database\Eloquent\Casts\AsCollection'));
        $this->assertSame('MyCustomCast', $analyzer->publicNormalizeCast('App\Casts\MyCustomCast'));
    }

    public function test_simple_cast_is_returned_as_is(): void
    {
        $analyzer = new class extends ClassAnalyzer {
            public function publicNormalizeCast(string $cast): string
            {
                return $this->normalizeCastType($cast);
            }
        };

        $this->assertSame('boolean', $analyzer->publicNormalizeCast('boolean'));
        $this->assertSame('integer', $analyzer->publicNormalizeCast('integer'));
        $this->assertSame('datetime', $analyzer->publicNormalizeCast('datetime'));
    }

    // -------------------------------------------------------------------------
    // Relations — User (hasMany, morphTo)
    // -------------------------------------------------------------------------

    public function test_has_many_relation_detected_on_user(): void
    {
        $data     = $this->analyzer()->readTargetedClasses(User::class, isEloquentModel: true);
        $byMethod = array_column($data['relations'], null, 'method');

        $this->assertArrayHasKey('posts', $byMethod);

        $rel = $byMethod['posts'];
        $this->assertSame('hasMany', $rel['kind']);
        $this->assertSame('Post',    $rel['related']);
    }

    public function test_morph_to_relation_detected_on_user(): void
    {
        $data     = $this->analyzer()->readTargetedClasses(User::class, isEloquentModel: true);
        $byMethod = array_column($data['relations'], null, 'method');

        $this->assertArrayHasKey('imageable', $byMethod);

        $rel = $byMethod['imageable'];
        $this->assertSame('morphTo', $rel['kind']);
        $this->assertSame('*',       $rel['related']);
        $this->assertNull($rel['relatedFqcn']);
    }

    public function test_relation_methods_excluded_from_methods_list(): void
    {
        $data      = $this->analyzer()->readTargetedClasses(User::class, isEloquentModel: true);
        $methods   = array_column($data['methods'], 'name');
        $relations = array_column($data['relations'], 'method');

        foreach ($relations as $relation) {
            $this->assertNotContains($relation, $methods);
        }
    }

    public function test_relation_result_contains_required_keys(): void
    {
        $data     = $this->analyzer()->readTargetedClasses(User::class, isEloquentModel: true);
        $relation = $data['relations'][0] ?? null;

        $this->assertNotNull($relation);
        $this->assertArrayHasKey('kind',        $relation);
        $this->assertArrayHasKey('method',      $relation);
        $this->assertArrayHasKey('related',     $relation);
        $this->assertArrayHasKey('relatedFqcn', $relation);
    }

    // -------------------------------------------------------------------------
    // Relations — Post (belongsTo)
    // -------------------------------------------------------------------------

    public function test_belongs_to_relation_detected_on_post(): void
    {
        $data     = $this->analyzer()->readTargetedClasses(Post::class, isEloquentModel: true);
        $byMethod = array_column($data['relations'], null, 'method');

        $this->assertArrayHasKey('user', $byMethod);

        $rel = $byMethod['user'];
        $this->assertSame('belongsTo', $rel['kind']);
        $this->assertSame('User',      $rel['related']);
        $this->assertSame(User::class, $rel['relatedFqcn']);
    }

    public function test_no_relations_when_eloquent_mode_disabled(): void
    {
        $data = $this->analyzer()->readTargetedClasses(Post::class, isEloquentModel: false);

        $this->assertEmpty($data['relations']);
    }

    public function test_relation_method_stays_in_methods_list_when_eloquent_disabled(): void
    {
        $data    = $this->analyzer()->readTargetedClasses(Post::class, isEloquentModel: false);
        $methods = array_column($data['methods'], 'name');

        // When not in Eloquent mode, 'user()' is a regular method and must appear
        $this->assertContains('user', $methods);
    }
}
