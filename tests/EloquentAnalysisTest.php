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
        $data = $this->analyzer()->analyze(User::class, isEloquentModel: true);

        $this->assertTrue($data['isEloquent']);
    }

    public function test_eloquent_model_not_flagged_when_mode_disabled(): void
    {
        $data = $this->analyzer()->analyze(User::class, isEloquentModel: false);

        $this->assertFalse($data['isEloquent']);
        $this->assertEmpty($data['relations']);
    }

    // -------------------------------------------------------------------------
    // Eloquent properties — User ($fillable, $hidden, $appends, $casts)
    // -------------------------------------------------------------------------

    public function test_fillable_fields_are_public(): void
    {
        $data  = $this->analyzer()->analyze(User::class, isEloquentModel: true);
        $props = array_column($data['properties'], 'visibility', 'name');

        $this->assertSame('public', $props['name']);
        $this->assertSame('public', $props['email']);
    }

    public function test_hidden_field_in_fillable_becomes_private(): void
    {
        $data  = $this->analyzer()->analyze(User::class, isEloquentModel: true);
        $props = array_column($data['properties'], 'visibility', 'name');

        // 'password' is in both $fillable and $hidden
        $this->assertSame('private', $props['password']);
    }

    public function test_appends_fields_are_public(): void
    {
        $data  = $this->analyzer()->analyze(User::class, isEloquentModel: true);
        $props = array_column($data['properties'], 'visibility', 'name');

        $this->assertSame('public', $props['full_name']);
    }

    public function test_cast_type_resolved_for_boolean(): void
    {
        $data  = $this->analyzer()->analyze(User::class, isEloquentModel: true);
        $props = array_column($data['properties'], 'type', 'name');

        $this->assertSame('boolean', $props['is_admin']);
    }

    public function test_fields_are_not_duplicated(): void
    {
        $data  = $this->analyzer()->analyze(User::class, isEloquentModel: true);
        $names = array_column($data['properties'], 'name');

        // 'password' appears in $fillable and $hidden — must appear only once
        $this->assertSame(1, count(array_filter($names, fn ($n) => $n === 'password')));
    }

    public function test_source_is_eloquent(): void
    {
        $data = $this->analyzer()->analyze(User::class, isEloquentModel: true);

        foreach ($data['properties'] as $prop) {
            $this->assertSame('eloquent', $prop['source']);
        }
    }

    // -------------------------------------------------------------------------
    // Eloquent properties — Post ($fillable, $hidden, $casts)
    // -------------------------------------------------------------------------

    public function test_post_hidden_field_not_in_fillable_is_private(): void
    {
        $data  = $this->analyzer()->analyze(Post::class, isEloquentModel: true);
        $props = array_column($data['properties'], 'visibility', 'name');

        // 'body' is in $fillable AND $hidden
        $this->assertSame('private', $props['body']);
    }

    public function test_post_cast_type_datetime(): void
    {
        $data  = $this->analyzer()->analyze(Post::class, isEloquentModel: true);
        $props = array_column($data['properties'], 'type', 'name');

        $this->assertSame('datetime', $props['published_at']);
        $this->assertSame('integer',  $props['views']);
    }

    // -------------------------------------------------------------------------
    // Relations — User (hasMany, morphTo)
    // -------------------------------------------------------------------------

    public function test_has_many_relation_detected_on_user(): void
    {
        $data = $this->analyzer()->analyze(User::class, isEloquentModel: true);

        $byMethod = array_column($data['relations'], null, 'method');
        $this->assertArrayHasKey('posts', $byMethod);

        $rel = $byMethod['posts'];
        $this->assertSame('hasMany', $rel['kind']);
        $this->assertSame('Post',    $rel['related']);
    }

    public function test_morph_to_relation_detected_on_user(): void
    {
        $data = $this->analyzer()->analyze(User::class, isEloquentModel: true);

        $byMethod = array_column($data['relations'], null, 'method');
        $this->assertArrayHasKey('imageable', $byMethod);

        $rel = $byMethod['imageable'];
        $this->assertSame('morphTo', $rel['kind']);
        $this->assertSame('*',       $rel['related']);
        $this->assertNull($rel['relatedFqcn']);
    }

    public function test_relation_methods_excluded_from_methods_list(): void
    {
        $data      = $this->analyzer()->analyze(User::class, isEloquentModel: true);
        $methods   = array_column($data['methods'], 'name');
        $relations = array_column($data['relations'], 'method');

        foreach ($relations as $relation) {
            $this->assertNotContains($relation, $methods);
        }
    }

    // -------------------------------------------------------------------------
    // Relations — Post (belongsTo)
    // -------------------------------------------------------------------------

    public function test_belongs_to_relation_detected_on_post(): void
    {
        $data = $this->analyzer()->analyze(Post::class, isEloquentModel: true);

        $byMethod = array_column($data['relations'], null, 'method');
        $this->assertArrayHasKey('user', $byMethod);

        $rel = $byMethod['user'];
        $this->assertSame('belongsTo', $rel['kind']);
        $this->assertSame('User',      $rel['related']);
        $this->assertSame(User::class, $rel['relatedFqcn']);
    }
}
