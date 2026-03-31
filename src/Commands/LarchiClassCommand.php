<?php

namespace Quatrebarbes\Larchiclass\Commands;

use Illuminate\Console\Command;
use Quatrebarbes\Larchiclass\Analyzers\ClassAnalyzer;
use Quatrebarbes\Larchiclass\Generators\PlantUmlGenerator;

class LarchiClassCommand extends Command
{
    protected $signature = 'larchi:class
                            {--namespace= : Namespace to analyze (default: App)}
                            {--output=    : Output file path (default: larchi_classes.puml)}
                            {--with-vendor : Expand vendor classes in structural relations}';

    protected $description = 'Analyze PHP classes and generate a PlantUML class diagram (generic, no Eloquent-specific logic)';

    public function handle(ClassAnalyzer $analyzer, PlantUmlGenerator $generator): int
    {
        $namespace  = $this->option('namespace') ?? 'App';
        $output     = $this->option('output')    ?? base_path('larchi_classes.puml');
        $withVendor = (bool) $this->option('with-vendor');

        $this->info("🔍 Analyzing namespace: <comment>{$namespace}</comment>");

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
            // Pass withVendor=false to analyze; force eloquentMode=false
            $classData[] = $analyzer->analyzeAsPlainClass($fqcn, $withVendor);
        }

        $puml = $generator->generate($classData, $withVendor, keepRelationMethods: false);
        file_put_contents($output, $puml);

        $this->newLine();
        $this->info("✅ Diagram generated: <comment>{$output}</comment>");

        return self::SUCCESS;
    }
}
