<?php

namespace Quatrebarbes\Larchiclass\Commands;

class LarchiModelCommand extends BaseLarchiCommand
{
    protected $signature = 'larchi:model
                            {--namespace=App\Models : Namespace to analyze}
                            {--output=larchi-model.puml : Output file path}
                            {--with-related : Display related classes}
                            {--with-vendors : Display vendor classes}';

    protected $description = 'Analyze Eloquent models and generate a PlantUML class diagram (with fillable properties and relationships)';

    protected function isEloquentMode(): bool
    {
        return true;
    }
}
