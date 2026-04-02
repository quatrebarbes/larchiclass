<?php

namespace Quatrebarbes\Larchiclass\Commands;

class LarchiModelCommand extends BaseLarchiCommand
{
    protected $signature = 'larchi:model
                            {--namespace=  : Namespace to analyze (default: App\Models)}
                            {--output=     : Output file path (default: larchi-model.puml)}
                            {--with-vendor : Expand vendor classes in structural relations}';

    protected $description = 'Analyze Eloquent models and generate a PlantUML class diagram (with fillable properties and relationships)';

    protected function defaultNamespace(): string
    {
        return 'App\Models';
    }

    protected function defaultOutput(): string
    {
        return base_path('larchi-model.puml');
    }

    protected function isEloquentMode(): bool
    {
        return true;
    }
}
