<?php

namespace Quatrebarbes\Larchiclass;

use Illuminate\Support\ServiceProvider;
use Quatrebarbes\Larchiclass\Analyzers\ClassAnalyzer;
use Quatrebarbes\Larchiclass\Analyzers\EloquentRelationshipExtractor;
use Quatrebarbes\Larchiclass\Commands\LarchiCallerCommand;
use Quatrebarbes\Larchiclass\Commands\LarchiClassCommand;
use Quatrebarbes\Larchiclass\Commands\LarchiModelCommand;
use Quatrebarbes\Larchiclass\Generators\ClassUmlGenerator;

class LarchiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(EloquentRelationshipExtractor::class);

        $this->app->singleton(ClassAnalyzer::class, fn ($app) =>
            new ClassAnalyzer($app->make(EloquentRelationshipExtractor::class))
        );

        $this->app->singleton(ClassUmlGenerator::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                LarchiCallerCommand::class,
                LarchiClassCommand::class,
                LarchiModelCommand::class,
            ]);
        }
    }
}
