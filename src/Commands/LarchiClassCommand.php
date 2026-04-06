<?php

namespace Quatrebarbes\Larchiclass\Commands;

class LarchiClassCommand extends BaseLarchiCommand
{
    protected $signature = 'larchi:class
                            {--namespace=App : Namespace to analyze}
                            {--output=larchi-class.puml : Output file path}
                            {--with-related : Display related classes}
                            {--with-vendors : Display vendor classes}';

    protected $description = 'Analyze PHP classes and generate a PlantUML class diagram (generic, no Eloquent-specific logic)';

    protected function isEloquentMode(): bool
    {
        return false;
    }
}
