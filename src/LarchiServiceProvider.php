<?php

namespace Quatrebarbes\Larchiclass;

use Illuminate\Support\ServiceProvider;
use Quatrebarbes\Larchiclass\Analyzers\ClassAnalyzer;
use Quatrebarbes\Larchiclass\Analyzers\RelationshipExtractor;
use Quatrebarbes\Larchiclass\Commands\LarchiClassCommand;
use Quatrebarbes\Larchiclass\Commands\LarchiModelCommand;
use Quatrebarbes\Larchiclass\Generators\PlantUmlGenerator;

class LarchiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(RelationshipExtractor::class);

        $this->app->singleton(ClassAnalyzer::class, fn ($app) =>
            new ClassAnalyzer($app->make(RelationshipExtractor::class))
        );

        $this->app->singleton(PlantUmlGenerator::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                LarchiModelCommand::class,
                LarchiClassCommand::class,
            ]);
        }
    }
}
