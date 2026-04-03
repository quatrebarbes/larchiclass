<?php

namespace Quatrebarbes\Larchiclass\Commands;

use Illuminate\Console\Command;
use Quatrebarbes\Larchiclass\Analyzers\ClassAnalyzer;
use Quatrebarbes\Larchiclass\Generators\PlantUmlGenerator;

abstract class BaseLarchiCommand extends Command
{
    abstract protected function defaultNamespace(): string;
    abstract protected function defaultOutput(): string;
    abstract protected function isEloquentMode(): bool;

    public function handle(ClassAnalyzer $analyzer, PlantUmlGenerator $generator): int
    {
        $namespace   = $this->option('namespace') ?? $this->defaultNamespace();
        $output      = $this->option('output')    ?? $this->defaultOutput();
        $withRelated = (bool) $this->option('with-related');
        $withVendors = (bool) $this->option('with-vendors');
        $isEloquent  = $this->isEloquentMode();

        $this->info("🔍 Analyzing namespace: <comment>{$namespace}</comment>");

        $classes = $analyzer->listClasses($namespace, $withVendors, $isEloquent);
        if (empty($classes)) {
            $this->warn("No classes found in namespace [{$namespace}].");
            $this->line('Make sure the namespace is loaded via Composer autoload.');
            return self::FAILURE;
        }

        $this->info('Found <comment>' . count($classes) . '</comment> class(es)...');
        $classes = array_map(function (string $fqcn) use ($analyzer, $isEloquent): array {
            $this->line("  → <comment>{$fqcn}</comment>");
            return $analyzer->readTargetedClasses($fqcn, $isEloquent);
        }, $classes);

        if ($withRelated) {
            $this->info('Related classes analysis...');
            $classes = [...$classes, ...$analyzer->readRelatedClasses($classes, $withVendors)];
        }

        $this->newLine();
        $this->info('Generating diagram...');
        file_put_contents($output, $generator->generate($classes));
        $this->info("✅ Diagram generated: <comment>{$output}</comment>");

        return self::SUCCESS;
    }
}
