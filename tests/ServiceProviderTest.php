<?php

namespace Quatrebarbes\Larchiclass\Tests;

use Illuminate\Support\Facades\Artisan;
use Orchestra\Testbench\TestCase;
use Quatrebarbes\Larchiclass\Analyzers\ClassAnalyzer;
use Quatrebarbes\Larchiclass\Analyzers\EloquentRelationshipExtractor;
use Quatrebarbes\Larchiclass\Generators\ClassUmlGenerator;
use Quatrebarbes\Larchiclass\LarchiServiceProvider;

class ServiceProviderTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [LarchiServiceProvider::class];
    }

    public function test_class_analyzer_bound_as_singleton(): void
    {
        $a = $this->app->make(ClassAnalyzer::class);
        $b = $this->app->make(ClassAnalyzer::class);

        $this->assertSame($a, $b);
    }

    public function test_relationship_extractor_bound_as_singleton(): void
    {
        $a = $this->app->make(EloquentRelationshipExtractor::class);
        $b = $this->app->make(EloquentRelationshipExtractor::class);

        $this->assertSame($a, $b);
    }

    public function test_plantuml_generator_bound_as_singleton(): void
    {
        $a = $this->app->make(ClassUmlGenerator::class);
        $b = $this->app->make(ClassUmlGenerator::class);

        $this->assertSame($a, $b);
    }

    public function test_larchi_caller_command_registered(): void
    {
        $this->assertArrayHasKey('larchi:caller', Artisan::all());
    }

    public function test_larchi_class_command_registered(): void
    {
        $this->assertArrayHasKey('larchi:class', Artisan::all());
    }

    public function test_larchi_model_command_registered(): void
    {
        $this->assertArrayHasKey('larchi:model', Artisan::all());
    }
}
