<?php

namespace Quatrebarbes\Larchiclass\Tests;

use Orchestra\Testbench\TestCase;
use Quatrebarbes\Larchiclass\Generators\PlantUmlGenerator;
use Quatrebarbes\Larchiclass\LarchiServiceProvider;

class PlantUmlGeneratorTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [LarchiServiceProvider::class];
    }

    private function generator(): PlantUmlGenerator
    {
        return new PlantUmlGenerator();
    }

    // -------------------------------------------------------------------------
    // Helpers — minimal class fixtures
    // -------------------------------------------------------------------------

    private function makeClass(array $overrides = []): array
    {
        return array_merge([
            'fqcn'            => 'App\Models\User',
            'name'            => 'User',
            'namespace'       => 'App\Models',
            'isAbstract'      => false,
            'isInterface'     => false,
            'isTrait'         => false,
            'isEnum'          => false,
            'isEloquent'      => false,
            'isVendor'        => false,
            'parent'          => null,
            'parentIsVendor'  => false,
            'interfaces'      => [],
            'traits'          => [],
            'withVendor'      => false,
            'relations'       => [],
            'relationMethods' => [],
            'properties'      => [],
            'methods'         => [],
        ], $overrides);
    }

    private function makeProperty(array $overrides = []): array
    {
        return array_merge([
            'name'       => 'name',
            'type'       => 'string',
            'visibility' => 'public',
            'static'     => false,
        ], $overrides);
    }

    private function makeMethod(array $overrides = []): array
    {
        return array_merge([
            'name'       => 'doSomething',
            'visibility' => 'public',
            'static'     => false,
            'abstract'   => false,
            'return'     => null,
            'params'     => [],
        ], $overrides);
    }

    // -------------------------------------------------------------------------
    // Envelope
    // -------------------------------------------------------------------------

    public function test_output_wrapped_in_plantuml_tags(): void
    {
        $output = $this->generator()->generate([$this->makeClass()]);

        $this->assertStringStartsWith('@startuml', $output);
        $this->assertStringContainsString('@enduml', $output);
    }

    public function test_output_ends_with_newline(): void
    {
        $output = $this->generator()->generate([$this->makeClass()]);

        $this->assertStringEndsWith(PHP_EOL, $output);
    }

    public function test_skinparam_always_present(): void
    {
        $output = $this->generator()->generate([$this->makeClass()]);

        $this->assertStringContainsString('skinparam classAttributeIconSize 0', $output);
        $this->assertStringContainsString('hide empty members', $output);
    }

    public function test_vendor_skinparam_only_when_vendor_classes_present(): void
    {
        $noVendor   = $this->generator()->generate([$this->makeClass()]);
        $withVendor = $this->generator()->generate(
            [$this->makeClass()],
            [$this->makeClass(['name' => 'Base', 'isVendor' => true, 'isStub' => true])]
        );

        $this->assertStringNotContainsString('BackgroundColor<<vendor>>', $noVendor);
        $this->assertStringContainsString('BackgroundColor<<vendor>>', $withVendor);
    }

    // -------------------------------------------------------------------------
    // Class block — keywords and stereotypes
    // -------------------------------------------------------------------------

    public function test_regular_class_keyword(): void
    {
        $output = $this->generator()->generate([$this->makeClass(['name' => 'Foo'])]);

        $this->assertStringContainsString('class Foo {', $output);
    }

    public function test_abstract_class_keyword(): void
    {
        $output = $this->generator()->generate([$this->makeClass(['name' => 'Foo', 'isAbstract' => true])]);

        $this->assertStringContainsString('abstract class Foo {', $output);
    }

    public function test_interface_keyword(): void
    {
        $output = $this->generator()->generate([$this->makeClass(['name' => 'Foo', 'isInterface' => true])]);

        $this->assertStringContainsString('interface Foo {', $output);
    }

    public function test_enum_keyword(): void
    {
        $output = $this->generator()->generate([$this->makeClass(['name' => 'Foo', 'isEnum' => true])]);

        $this->assertStringContainsString('enum Foo {', $output);
    }

    public function test_trait_stereotype(): void
    {
        $output = $this->generator()->generate([$this->makeClass(['name' => 'Foo', 'isTrait' => true])]);

        $this->assertStringContainsString('class Foo <<trait>>', $output);
    }

    public function test_model_stereotype(): void
    {
        $output = $this->generator()->generate([$this->makeClass(['name' => 'Foo', 'isEloquent' => true])]);

        $this->assertStringContainsString('class Foo <<model>>', $output);
    }

    public function test_vendor_stereotype(): void
    {
        $output = $this->generator()->generate(
            [$this->makeClass()],
            [$this->makeClass(['name' => 'Foo', 'isVendor' => true, 'isStub' => true])]
        );

        $this->assertStringContainsString('class Foo <<vendor>>', $output);
    }

    // -------------------------------------------------------------------------
    // Class block — properties and methods
    // -------------------------------------------------------------------------

    public function test_property_visibility_symbols(): void
    {
        $class  = $this->makeClass(['properties' => [
            $this->makeProperty(['name' => 'pub',  'visibility' => 'public']),
            $this->makeProperty(['name' => 'prot', 'visibility' => 'protected']),
            $this->makeProperty(['name' => 'priv', 'visibility' => 'private']),
        ]]);
        $output = $this->generator()->generate([$class]);

        $this->assertStringContainsString('+ pub', $output);
        $this->assertStringContainsString('# prot', $output);
        $this->assertStringContainsString('- priv', $output);
    }

    public function test_property_type_rendered(): void
    {
        $class  = $this->makeClass(['properties' => [
            $this->makeProperty(['name' => 'email', 'type' => 'string']),
        ]]);
        $output = $this->generator()->generate([$class]);

        $this->assertStringContainsString('email : string', $output);
    }

    public function test_static_property_has_modifier(): void
    {
        $class  = $this->makeClass(['properties' => [
            $this->makeProperty(['name' => 'instance', 'static' => true]),
        ]]);
        $output = $this->generator()->generate([$class]);

        $this->assertStringContainsString('{static} instance', $output);
    }

    public function test_property_without_type_omits_colon(): void
    {
        $class  = $this->makeClass(['properties' => [
            $this->makeProperty(['name' => 'data', 'type' => null]),
        ]]);
        $output = $this->generator()->generate([$class]);

        $this->assertStringContainsString('data', $output);
        $this->assertStringNotContainsString('data :', $output);
    }

    public function test_separator_rendered_between_properties_and_methods(): void
    {
        $class = $this->makeClass([
            'properties' => [$this->makeProperty()],
            'methods'    => [$this->makeMethod()],
        ]);
        $output = $this->generator()->generate([$class]);

        $this->assertStringContainsString('    --', $output);
    }

    public function test_no_separator_when_no_properties(): void
    {
        $class  = $this->makeClass(['methods' => [$this->makeMethod()]]);
        $output = $this->generator()->generate([$class]);

        $this->assertStringNotContainsString('    --', $output);
    }

    public function test_abstract_method_has_modifier(): void
    {
        $class  = $this->makeClass(['methods' => [
            $this->makeMethod(['name' => 'doIt', 'abstract' => true]),
        ]]);
        $output = $this->generator()->generate([$class]);

        $this->assertStringContainsString('{abstract} doIt', $output);
    }

    public function test_static_method_has_modifier(): void
    {
        $class  = $this->makeClass(['methods' => [
            $this->makeMethod(['name' => 'create', 'static' => true]),
        ]]);
        $output = $this->generator()->generate([$class]);

        $this->assertStringContainsString('{static} create', $output);
    }

    public function test_vendor_class_body_is_empty(): void
    {
        $vendor = $this->makeClass([
            'name'       => 'Model',
            'isVendor'   => true,
            'properties' => [$this->makeProperty()],
            'methods'    => [$this->makeMethod()],
        ]);
        $output = $this->generator()->generate([$this->makeClass()], [$vendor]);

        // Members should not appear for vendor classes
        $this->assertStringNotContainsString('+ name', $output);
        $this->assertStringNotContainsString('+ doSomething', $output);
    }

    // -------------------------------------------------------------------------
    // Type escaping
    // -------------------------------------------------------------------------

    public function test_generic_type_angle_brackets_escaped(): void
    {
        $class = $this->makeClass(['properties' => [
            $this->makeProperty(['name' => 'items', 'type' => 'Collection<Model>']),
        ]]);
        $output = $this->generator()->generate([$class]);

        $this->assertStringContainsString('Collection{Model}', $output);
        $this->assertStringNotContainsString('Collection<Model>', $output);
    }

    // -------------------------------------------------------------------------
    // Structural relations
    // -------------------------------------------------------------------------

    public function test_inheritance_arrow_rendered(): void
    {
        $parent = $this->makeClass(['fqcn' => 'App\Base', 'name' => 'Base']);
        $child  = $this->makeClass(['fqcn' => 'App\User', 'name' => 'User', 'parent' => 'App\Base']);

        $output = $this->generator()->generate([$parent, $child]);

        $this->assertStringContainsString('Base <|-- User', $output);
    }

    public function test_interface_implementation_arrow(): void
    {
        $iface = $this->makeClass(['fqcn' => 'App\Contracts\Fooable', 'name' => 'Fooable', 'isInterface' => true]);
        $class = $this->makeClass(['fqcn' => 'App\Foo', 'name' => 'Foo', 'interfaces' => ['App\Contracts\Fooable']]);

        $output = $this->generator()->generate([$iface, $class]);

        $this->assertStringContainsString('Fooable <|.. Foo', $output);
    }

    public function test_trait_usage_arrow(): void
    {
        $trait = $this->makeClass(['fqcn' => 'App\HasTimestamps', 'name' => 'HasTimestamps', 'isTrait' => true]);
        $class = $this->makeClass(['fqcn' => 'App\Post', 'name' => 'Post', 'traits' => ['App\HasTimestamps']]);

        $output = $this->generator()->generate([$trait, $class]);

        $this->assertStringContainsString('HasTimestamps <.. Post : <<uses>>', $output);
    }

    public function test_out_of_scope_parent_produces_no_arrow(): void
    {
        // Parent FQCN is not in the class list
        $child  = $this->makeClass(['parent' => 'Illuminate\Database\Eloquent\Model']);
        $output = $this->generator()->generate([$child]);

        $this->assertStringNotContainsString('<|--', $output);
    }

    // -------------------------------------------------------------------------
    // Eloquent relations
    // -------------------------------------------------------------------------

    public function test_has_many_cardinality(): void
    {
        $user = $this->makeClass(['name' => 'User', 'fqcn' => 'App\User', 'relations' => [
            ['kind' => 'hasMany', 'method' => 'posts', 'related' => 'Post', 'relatedFqcn' => 'App\Post'],
        ]]);
        $post = $this->makeClass(['name' => 'Post', 'fqcn' => 'App\Post']);

        $output = $this->generator()->generate([$user, $post]);

        $this->assertStringContainsString('User "1" -- "*" Post', $output);
    }

    public function test_belongs_to_cardinality(): void
    {
        $post = $this->makeClass(['name' => 'Post', 'fqcn' => 'App\Post', 'relations' => [
            ['kind' => 'belongsTo', 'method' => 'user', 'related' => 'User', 'relatedFqcn' => 'App\User'],
        ]]);
        $user = $this->makeClass(['name' => 'User', 'fqcn' => 'App\User']);

        $output = $this->generator()->generate([$post, $user]);

        $this->assertStringContainsString('Post "*" -- "1" User', $output);
    }

    public function test_reciprocal_relations_merged_into_single_arrow(): void
    {
        $user = $this->makeClass(['name' => 'User', 'fqcn' => 'App\User', 'relations' => [
            ['kind' => 'hasMany', 'method' => 'posts', 'related' => 'Post', 'relatedFqcn' => 'App\Post'],
        ]]);
        $post = $this->makeClass(['name' => 'Post', 'fqcn' => 'App\Post', 'relations' => [
            ['kind' => 'belongsTo', 'method' => 'user', 'related' => 'User', 'relatedFqcn' => 'App\User'],
        ]]);

        $output = $this->generator()->generate([$user, $post]);

        // Only one arrow line between User and Post
        $arrows = array_filter(
            explode(PHP_EOL, $output),
            fn ($l) => str_contains($l, 'User') && str_contains($l, 'Post') && str_contains($l, ' -- ')
        );
        $this->assertCount(1, $arrows);
    }

    public function test_reciprocal_cardinality_taken_from_has_side(): void
    {
        $user = $this->makeClass(['name' => 'User', 'fqcn' => 'App\User', 'relations' => [
            ['kind' => 'hasMany', 'method' => 'posts', 'related' => 'Post', 'relatedFqcn' => 'App\Post'],
        ]]);
        $post = $this->makeClass(['name' => 'Post', 'fqcn' => 'App\Post', 'relations' => [
            ['kind' => 'belongsTo', 'method' => 'user', 'related' => 'User', 'relatedFqcn' => 'App\User'],
        ]]);

        $output = $this->generator()->generate([$user, $post]);

        // hasMany cardinality: "1" -- "*"
        $this->assertStringContainsString('User "1" -- "*" Post', $output);
    }

    public function test_morph_to_rendered_as_dashed_line(): void
    {
        $user = $this->makeClass(['name' => 'User', 'fqcn' => 'App\User', 'relations' => [
            ['kind' => 'morphTo', 'method' => 'imageable', 'related' => '*', 'relatedFqcn' => null],
        ]]);

        $output = $this->generator()->generate([$user]);

        $this->assertStringContainsString('User .. * : imageable (morphTo)', $output);
    }

    public function test_belongs_to_many_cardinality(): void
    {
        $user = $this->makeClass(['name' => 'User', 'fqcn' => 'App\User', 'relations' => [
            ['kind' => 'belongsToMany', 'method' => 'roles', 'related' => 'Role', 'relatedFqcn' => 'App\Role'],
        ]]);
        $role = $this->makeClass(['name' => 'Role', 'fqcn' => 'App\Role']);

        $output = $this->generator()->generate([$user, $role]);

        $this->assertStringContainsString('User "*" -- "*" Role', $output);
    }

    public function test_has_one_cardinality(): void
    {
        $user    = $this->makeClass(['name' => 'User', 'fqcn' => 'App\User', 'relations' => [
            ['kind' => 'hasOne', 'method' => 'profile', 'related' => 'Profile', 'relatedFqcn' => 'App\Profile'],
        ]]);
        $profile = $this->makeClass(['name' => 'Profile', 'fqcn' => 'App\Profile']);

        $output = $this->generator()->generate([$user, $profile]);

        $this->assertStringContainsString('User "1" -- "0..1" Profile', $output);
    }

    public function test_relation_label_contains_method_and_kind(): void
    {
        $user = $this->makeClass(['name' => 'User', 'fqcn' => 'App\User', 'relations' => [
            ['kind' => 'hasMany', 'method' => 'posts', 'related' => 'Post', 'relatedFqcn' => 'App\Post'],
        ]]);
        $post = $this->makeClass(['name' => 'Post', 'fqcn' => 'App\Post']);

        $output = $this->generator()->generate([$user, $post]);

        $this->assertStringContainsString('posts', $output);
        $this->assertStringContainsString('hasMany', $output);
    }
}
