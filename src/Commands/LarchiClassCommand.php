<?php

namespace Quatrebarbes\Larchiclass\Commands;

class LarchiClassCommand extends BaseLarchiCommand
{
    protected $signature = 'larchi:class
                            {--namespace=  : Namespace to analyze (default: App)}
                            {--output=     : Output file path (default: larchi-class.puml)}
                            {--with-vendor : Expand vendor classes in structural relations}';

    protected $description = 'Analyze PHP classes and generate a PlantUML class diagram (generic, no Eloquent-specific logic)';

    protected function defaultNamespace(): string
    {
        return 'App';
    }

    protected function defaultOutput(): string
    {
        return base_path('larchi-class.puml');
    }

    protected function isEloquentMode(): bool
    {
        return false;
    }
}
