<?php

namespace Quatrebarbes\Larchiclass\Commands;

use Illuminate\Console\Command;
use Quatrebarbes\Larchiclass\Analyzers\ClassAnalyzer;
use Quatrebarbes\Larchiclass\Generators\PlantUmlGenerator;

class LarchiModelCommand extends Command
{
    protected $signature = 'larchi:model
                            {--namespace= : Namespace to analyze (default: App\\Models)}
                            {--output=    : Output file path (default: larchi-model.puml)}
                            {--with-vendor           : Expand vendor classes in structural relations}
                            {--keep-relation-methods : Keep relation methods in class blocks (in addition to relation lines)}';

    protected $description = 'Analyze Eloquent models and generate a PlantUML class diagram (with fillable properties and relationships)';

    public function handle(ClassAnalyzer $analyzer, PlantUmlGenerator $generator): int
    {
        $namespace           = $this->option('namespace') ?? 'App\\Models';
        $output              = $this->option('output')    ?? base_path('larchi-model.puml');
        $withVendor          = (bool) $this->option('with-vendor');
        $keepRelationMethods = (bool) $this->option('keep-relation-methods');

        $this->info("🔍 Analyzing namespace: <comment>{$namespace}</comment>");

        if ($keepRelationMethods) {
            $this->line("  <comment>--keep-relation-methods</comment>: relation methods kept in class blocks.");
        }

        $classes = $analyzer->discoverClasses($namespace);

        if (empty($classes)) {
            $this->warn("No classes found in namespace [{$namespace}].");
            $this->line("Make sure the namespace is loaded via Composer autoload.");
            return self::FAILURE;
        }

        $this->info("Found <comment>" . count($classes) . "</comment> class(es). Generating diagram...");

        $classData = [];
        foreach ($classes as $fqcn) {
            $this->line("  → <comment>{$fqcn}</comment>");
            $classData[] = $analyzer->analyze($fqcn, $withVendor);
        }

        $puml = $generator->generate($classData, $withVendor, $keepRelationMethods);
        file_put_contents($output, $puml);

        $this->newLine();
        $this->info("✅ Diagram generated: <comment>{$output}</comment>");

        return self::SUCCESS;
    }
}
