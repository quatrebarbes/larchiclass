<?php

namespace Quatrebarbes\Larchiclass\Tests;

use Orchestra\Testbench\TestCase;
use Quatrebarbes\Larchiclass\ArchiServiceProvider;
use Quatrebarbes\Larchiclass\Generators\PlantUmlGenerator;

class PlantUmlGeneratorTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [ArchiServiceProvider::class];
    }

    private function sampleClass(): array
    {
        return [
            'fqcn'        => 'App\\Models\\User',
            'name'        => 'User',
            'namespace'   => 'App\\Models',
            'isAbstract'  => false,
            'isInterface' => false,
            'isTrait'     => false,
            'isEnum'      => false,
            'parent'      => 'Illuminate\\Database\\Eloquent\\Model',
            'interfaces'  => ['Illuminate\\Contracts\\Auth\\Authenticatable'],
            'traits'      => [],
            'properties'  => [
                ['name' => 'fillable', 'type' => 'array', 'visibility' => 'protected', 'static' => false],
                ['name' => 'hidden',   'type' => 'array', 'visibility' => 'protected', 'static' => false],
            ],
            'methods' => [
                ['name' => 'posts',    'visibility' => 'public',    'static' => false, 'abstract' => false, 'return' => 'HasMany', 'params' => []],
                ['name' => 'isAdmin',  'visibility' => 'public',    'static' => false, 'abstract' => false, 'return' => 'bool',    'params' => []],
                ['name' => 'scopeActive', 'visibility' => 'public', 'static' => false, 'abstract' => false, 'return' => null,      'params' => ['Builder $query']],
            ],
        ];
    }

    public function test_output_starts_and_ends_with_plantuml_tags(): void
    {
        $generator = new PlantUmlGenerator();
        $output    = $generator->generate([$this->sampleClass()]);

        $this->assertStringContainsString('@startuml', $output);
        $this->assertStringContainsString('@enduml', $output);
    }

    public function test_output_contains_class_name(): void
    {
        $generator = new PlantUmlGenerator();
        $output    = $generator->generate([$this->sampleClass()]);

        $this->assertStringContainsString('class User', $output);
    }

    public function test_output_contains_properties(): void
    {
        $generator = new PlantUmlGenerator();
        $output    = $generator->generate([$this->sampleClass()]);

        $this->assertStringContainsString('fillable', $output);
        $this->assertStringContainsString('hidden', $output);
    }

    public function test_output_contains_methods(): void
    {
        $generator = new PlantUmlGenerator();
        $output    = $generator->generate([$this->sampleClass()]);

        $this->assertStringContainsString('posts()', $output);
        $this->assertStringContainsString('isAdmin()', $output);
    }

    public function test_output_contains_inheritance_relation(): void
    {
        $parent = array_merge($this->sampleClass(), [
            'fqcn'   => 'Illuminate\\Database\\Eloquent\\Model',
            'name'   => 'Model',
            'parent' => null,
            'interfaces' => [],
            'properties' => [],
            'methods'    => [],
        ]);

        $generator = new PlantUmlGenerator();
        $output    = $generator->generate([$parent, $this->sampleClass()]);

        $this->assertStringContainsString('Model <|-- User', $output);
    }

    public function test_artisan_command_is_registered(): void
    {
        $this->assertArrayHasKey('archi:class', $this->app->make(\Illuminate\Console\Application::class)->all());
    }
}
