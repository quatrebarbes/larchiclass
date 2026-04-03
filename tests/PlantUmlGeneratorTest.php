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
            'fqcn'        => 'App\Models\User',
            'name'        => 'User',
            'namespace'   => 'App\Models',
            'isAbstract'  => false,
            'isInterface' => false,
            'isTrait'     => false,
            'isEnum'      => false,
            'isEloquent'  => false,
            'isVendor'    => false,
            'parent'      => null,
            'interfaces'  => [],
            'traits'      => [],
            'relations'   => [],
            'dependencies' => [],
            'properties'  => [],
            'methods'     => [],
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

    public function test_empty_class_list_produces_valid_envelope(): void
    {
        $output = $this->generator()->generate([]);

        $this->assertStringStartsWith('@startuml', $output);
        $this->assertStringContainsString('@enduml', $output);
    }

    // -------------------------------------------------------------------------
    // Class block — keywords and stereotypes
    // -------------------------------------------------------------------------

    public function test_regular_class_keyword(): void
    {
        $output = $this->generator()->generate([$this->makeClass([
            'fqcn' => 'App\Models\Foo',
        ])]);

        $this->assertStringContainsString('class App\Models\Foo {', $output);
    }

    public function test_abstract_class_keyword(): void
    {
        $output = $this->generator()->generate([$this->makeClass([
            'fqcn'       => 'App\Models\Foo',
            'isAbstract' => true,
        ])]);

        $this->assertStringContainsString('abstract class App\Models\Foo {', $output);
    }

    public function test_interface_keyword(): void
    {
        $output = $this->generator()->generate([$this->makeClass([
            'fqcn'        => 'App\Models\Foo',
            'isInterface' => true,
        ])]);

        $this->assertStringContainsString('interface App\Models\Foo {', $output);
    }

    public function test_enum_keyword(): void
    {
        $output = $this->generator()->generate([$this->makeClass([
            'fqcn'   => 'App\Models\Foo',
            'isEnum' => true,
        ])]);

        $this->assertStringContainsString('enum App\Models\Foo {', $output);
    }

    public function test_trait_stereotype(): void
    {
        $output = $this->generator()->generate([$this->makeClass([
            'fqcn'    => 'App\Models\Foo',
            'isTrait' => true,
        ])]);

        $this->assertStringContainsString('class App\Models\Foo <<trait>>', $output);
    }

    public function test_model_stereotype(): void
    {
        $output = $this->generator()->generate([$this->makeClass([
            'fqcn'      => 'App\Models\Foo',
            'isEloquent' => true,
        ])]);

        $this->assertStringContainsString('class App\Models\Foo <<model>>', $output);
    }

    public function test_vendor_stereotype(): void
    {
        $output = $this->generator()->generate([
            $this->makeClass(),
            $this->makeClass([
                'fqcn'     => 'App\Models\Foo',
                'isVendor' => true,
            ]),
        ]);

        $this->assertStringContainsString('class App\Models\Foo <<vendor>>', $output);
    }

    public function test_multiple_stereotypes_combined(): void
    {
        // A trait that is also a vendor class should carry both stereotypes
        $output = $this->generator()->generate([$this->makeClass([
            'fqcn'     => 'App\Models\Foo',
            'isTrait'  => true,
            'isVendor' => true,
        ])]);

        $this->assertStringContainsString('<<trait>>', $output);
        $this->assertStringContainsString('<<vendor>>', $output);
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

    public function test_class_with_no_members_still_renders_braces(): void
    {
        $output = $this->generator()->generate([$this->makeClass()]);

        $this->assertStringContainsString('App\Models\User {', $output);
        $this->assertStringContainsString('}', $output);
    }

    // -------------------------------------------------------------------------
    // Type escaping
    // -------------------------------------------------------------------------

    public function test_generic_type_angle_brackets_escaped(): void
    {
        $class  = $this->makeClass(['properties' => [
            $this->makeProperty(['name' => 'items', 'type' => 'Collection<Model>']),
        ]]);
        $output = $this->generator()->generate([$class]);

        $this->assertStringContainsString('Collection{Model}', $output);
        $this->assertStringNotContainsString('Collection<Model>', $output);
    }

    public function test_nested_generic_type_fully_escaped(): void
    {
        $class  = $this->makeClass(['properties' => [
            $this->makeProperty(['name' => 'map', 'type' => 'Map<string, List<int>>']),
        ]]);
        $output = $this->generator()->generate([$class]);

        $this->assertStringContainsString('Map{string, List{int}}', $output);
        $this->assertStringNotContainsString('Map<string, List<int>>', $output);
    }

    // -------------------------------------------------------------------------
    // Structural relations
    // -------------------------------------------------------------------------

    public function test_inheritance_arrow_rendered(): void
    {
        $parent = $this->makeClass(['fqcn' => 'App\Base', 'name' => 'Base']);
        $child  = $this->makeClass(['fqcn' => 'App\User', 'name' => 'User', 'parent' => 'App\Base']);

        $output = $this->generator()->generate([$parent, $child]);

        $this->assertStringContainsString('App\\Base <|-- App\\User', $output);
    }

    public function test_interface_implementation_arrow(): void
    {
        $iface = $this->makeClass(['fqcn' => 'App\Contracts\Fooable', 'name' => 'Fooable', 'isInterface' => true]);
        $class = $this->makeClass(['fqcn' => 'App\Foo', 'name' => 'Foo', 'interfaces' => ['App\Contracts\Fooable']]);

        $output = $this->generator()->generate([$iface, $class]);

        $this->assertStringContainsString('App\\Contracts\\Fooable <|.. App\\Foo', $output);
    }

    public function test_trait_usage_arrow(): void
    {
        $trait = $this->makeClass(['fqcn' => 'App\HasTimestamps', 'name' => 'HasTimestamps', 'isTrait' => true]);
        $class = $this->makeClass(['fqcn' => 'App\Post', 'name' => 'Post', 'traits' => ['App\HasTimestamps']]);

        $output = $this->generator()->generate([$trait, $class]);

        // The generator emits the full FQCN on both sides
        $this->assertStringContainsString('App\\HasTimestamps <.. App\\Post : <<uses>>', $output);
    }

    public function test_out_of_scope_parent_produces_no_arrow(): void
    {
        // Parent FQCN is not in the class list → no arrow emitted
        $child  = $this->makeClass(['parent' => 'Illuminate\Database\Eloquent\Model']);
        $output = $this->generator()->generate([$child]);

        $this->assertStringNotContainsString('<|--', $output);
    }

    public function test_out_of_scope_interface_produces_no_arrow(): void
    {
        $class  = $this->makeClass(['interfaces' => ['App\Contracts\SomeContract']]);
        $output = $this->generator()->generate([$class]);

        $this->assertStringNotContainsString('<|..', $output);
    }

    public function test_out_of_scope_trait_produces_no_arrow(): void
    {
        $class  = $this->makeClass(['traits' => ['App\SomeTrait']]);
        $output = $this->generator()->generate([$class]);

        $this->assertStringNotContainsString('<..', $output);
    }

    public function test_dependency_arrow_rendered_for_use_import(): void
    {
        $service    = $this->makeClass(['fqcn' => 'App\Services\UserService', 'name' => 'UserService']);
        $controller = $this->makeClass([
            'fqcn'         => 'App\Http\Controllers\UserController',
            'name'         => 'UserController',
            'dependencies' => ['App\Services\UserService'],
        ]);

        $output = $this->generator()->generate([$service, $controller]);

        $this->assertStringContainsString(
            'App\Http\Controllers\UserController ..> App\Services\UserService : <<uses>>',
            $output
        );
    }

    public function test_dependency_arrow_not_rendered_when_dep_out_of_scope(): void
    {
        $controller = $this->makeClass([
            'fqcn'         => 'App\Http\Controllers\UserController',
            'name'         => 'UserController',
            'dependencies' => ['App\Services\UserService'],
        ]);

        $output = $this->generator()->generate([$controller]);

        $this->assertStringNotContainsString('..>', $output);
    }

    public function test_dependency_arrow_not_in_eloquent_section(): void
    {
        $service    = $this->makeClass(['fqcn' => 'App\Services\UserService', 'name' => 'UserService']);
        $controller = $this->makeClass([
            'fqcn'         => 'App\Http\Controllers\UserController',
            'name'         => 'UserController',
            'dependencies' => ['App\Services\UserService'],
        ]);

        $output = $this->generator()->generate([$service, $controller]);

        $eloquentSectionStart = strpos($output, "' -- Eloquent relationships");

        if ($eloquentSectionStart !== false) {
            $eloquentSection = substr($output, $eloquentSectionStart);
            $this->assertStringNotContainsString('App\Services\UserService : <<uses>>', $eloquentSection);
        } else {
            $this->assertStringContainsString('..>', $output);
        }
    }

    // -------------------------------------------------------------------------
    // Eloquent relation arrows — cardinality
    // -------------------------------------------------------------------------

    public function test_has_many_cardinality(): void
    {
        $user = $this->makeClass(['fqcn' => 'App\User', 'name' => 'User', 'relations' => [
            ['kind' => 'hasMany', 'method' => 'posts', 'related' => 'Post', 'relatedFqcn' => 'App\Post'],
        ]]);
        $post = $this->makeClass(['fqcn' => 'App\Post', 'name' => 'Post']);

        $output = $this->generator()->generate([$user, $post]);

        $this->assertStringContainsString('App\\User "1" -- "*" App\\Post', $output);
    }

    public function test_has_one_cardinality(): void
    {
        $user    = $this->makeClass(['fqcn' => 'App\User', 'name' => 'User', 'relations' => [
            ['kind' => 'hasOne', 'method' => 'profile', 'related' => 'Profile', 'relatedFqcn' => 'App\Profile'],
        ]]);
        $profile = $this->makeClass(['fqcn' => 'App\Profile', 'name' => 'Profile']);

        $output = $this->generator()->generate([$user, $profile]);

        $this->assertStringContainsString('App\\User "1" -- "0..1" App\\Profile', $output);
    }

    public function test_belongs_to_cardinality(): void
    {
        $post = $this->makeClass(['fqcn' => 'App\Post', 'name' => 'Post', 'relations' => [
            ['kind' => 'belongsTo', 'method' => 'user', 'related' => 'User', 'relatedFqcn' => 'App\User'],
        ]]);
        $user = $this->makeClass(['fqcn' => 'App\User', 'name' => 'User']);

        $output = $this->generator()->generate([$post, $user]);

        $this->assertStringContainsString('App\\Post "*" -- "1" App\\User', $output);
    }

    public function test_belongs_to_many_cardinality(): void
    {
        $user = $this->makeClass(['fqcn' => 'App\User', 'name' => 'User', 'relations' => [
            ['kind' => 'belongsToMany', 'method' => 'roles', 'related' => 'Role', 'relatedFqcn' => 'App\Role'],
        ]]);
        $role = $this->makeClass(['fqcn' => 'App\Role', 'name' => 'Role']);

        $output = $this->generator()->generate([$user, $role]);

        $this->assertStringContainsString('App\\User "*" -- "*" App\\Role', $output);
    }

    public function test_has_many_through_cardinality(): void
    {
        $country = $this->makeClass(['fqcn' => 'App\Country', 'name' => 'Country', 'relations' => [
            ['kind' => 'hasManyThrough', 'method' => 'posts', 'related' => 'Post', 'relatedFqcn' => 'App\Post'],
        ]]);
        $post = $this->makeClass(['fqcn' => 'App\Post', 'name' => 'Post']);

        $output = $this->generator()->generate([$country, $post]);

        $this->assertStringContainsString('App\\Country "1" -- "*" App\\Post', $output);
    }

    public function test_relation_out_of_scope_uses_short_name_as_target(): void
    {
        // relatedFqcn not in the class list — short name used instead
        $user = $this->makeClass(['fqcn' => 'App\User', 'name' => 'User', 'relations' => [
            ['kind' => 'hasMany', 'method' => 'posts', 'related' => 'Post', 'relatedFqcn' => null],
        ]]);

        $output = $this->generator()->generate([$user]);

        // Arrow should use the short name 'Post' as target
        $this->assertStringContainsString('"1" -- "*" Post', $output);
    }

    // -------------------------------------------------------------------------
    // Reciprocal relation merging
    // -------------------------------------------------------------------------

    public function test_reciprocal_relations_merged_into_single_arrow(): void
    {
        $user = $this->makeClass(['fqcn' => 'App\User', 'name' => 'User', 'relations' => [
            ['kind' => 'hasMany', 'method' => 'posts', 'related' => 'Post', 'relatedFqcn' => 'App\Post'],
        ]]);
        $post = $this->makeClass(['fqcn' => 'App\Post', 'name' => 'Post', 'relations' => [
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
        $user = $this->makeClass(['fqcn' => 'App\User', 'name' => 'User', 'relations' => [
            ['kind' => 'hasMany', 'method' => 'posts', 'related' => 'Post', 'relatedFqcn' => 'App\Post'],
        ]]);
        $post = $this->makeClass(['fqcn' => 'App\Post', 'name' => 'Post', 'relations' => [
            ['kind' => 'belongsTo', 'method' => 'user', 'related' => 'User', 'relatedFqcn' => 'App\User'],
        ]]);

        $output = $this->generator()->generate([$user, $post]);

        // hasMany cardinality from the "has" side: "1" -- "*"
        $this->assertStringContainsString('App\\User "1" -- "*" App\\Post', $output);
    }

    public function test_reciprocal_label_contains_both_method_names(): void
    {
        $user = $this->makeClass(['fqcn' => 'App\User', 'name' => 'User', 'relations' => [
            ['kind' => 'hasMany', 'method' => 'posts', 'related' => 'Post', 'relatedFqcn' => 'App\Post'],
        ]]);
        $post = $this->makeClass(['fqcn' => 'App\Post', 'name' => 'Post', 'relations' => [
            ['kind' => 'belongsTo', 'method' => 'user', 'related' => 'User', 'relatedFqcn' => 'App\User'],
        ]]);

        $output = $this->generator()->generate([$user, $post]);

        $this->assertStringContainsString('posts', $output);
        $this->assertStringContainsString('user',  $output);
    }

    // -------------------------------------------------------------------------
    // morphTo
    // -------------------------------------------------------------------------

    public function test_morph_to_rendered_as_dashed_line(): void
    {
        $user = $this->makeClass(['fqcn' => 'App\User', 'name' => 'User', 'relations' => [
            ['kind' => 'morphTo', 'method' => 'imageable', 'related' => '*', 'relatedFqcn' => null],
        ]]);

        $output = $this->generator()->generate([$user]);

        $this->assertStringContainsString('App\\User .. * : imageable (morphTo)', $output);
    }

    // -------------------------------------------------------------------------
    // Relation labels
    // -------------------------------------------------------------------------

    public function test_relation_label_contains_method_and_kind(): void
    {
        $user = $this->makeClass(['fqcn' => 'App\User', 'name' => 'User', 'relations' => [
            ['kind' => 'hasMany', 'method' => 'posts', 'related' => 'Post', 'relatedFqcn' => 'App\Post'],
        ]]);
        $post = $this->makeClass(['fqcn' => 'App\Post', 'name' => 'Post']);

        $output = $this->generator()->generate([$user, $post]);

        $this->assertStringContainsString('posts',   $output);
        $this->assertStringContainsString('hasMany', $output);
    }

    // -------------------------------------------------------------------------
    // Section headers
    // -------------------------------------------------------------------------

    public function test_structural_relations_section_present_when_relations_exist(): void
    {
        $parent = $this->makeClass(['fqcn' => 'App\Base', 'name' => 'Base']);
        $child  = $this->makeClass(['fqcn' => 'App\User', 'name' => 'User', 'parent' => 'App\Base']);

        $output = $this->generator()->generate([$parent, $child]);

        $this->assertStringContainsString("' -- Structural relations", $output);
    }

    public function test_structural_relations_section_absent_when_no_relations(): void
    {
        $output = $this->generator()->generate([$this->makeClass()]);

        $this->assertStringNotContainsString("' -- Structural relations", $output);
    }

    public function test_eloquent_section_present_when_relations_exist(): void
    {
        $user = $this->makeClass(['fqcn' => 'App\User', 'name' => 'User', 'relations' => [
            ['kind' => 'hasMany', 'method' => 'posts', 'related' => 'Post', 'relatedFqcn' => 'App\Post'],
        ]]);
        $post = $this->makeClass(['fqcn' => 'App\Post', 'name' => 'Post']);

        $output = $this->generator()->generate([$user, $post]);

        $this->assertStringContainsString("' -- Eloquent relationships", $output);
    }

    public function test_eloquent_section_absent_when_no_relations(): void
    {
        $output = $this->generator()->generate([$this->makeClass()]);

        $this->assertStringNotContainsString("' -- Eloquent relationships", $output);
    }
}
