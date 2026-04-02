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
        $namespace  = $this->option('namespace') ?? $this->defaultNamespace();
        $output     = $this->option('output')    ?? $this->defaultOutput();
        $withVendor = (bool) $this->option('with-vendor');

        $this->info("🔍 Analyzing namespace: <comment>{$namespace}</comment>");

        $classes = $analyzer->discoverClasses($namespace);

        if (empty($classes)) {
            $this->warn("No classes found in namespace [{$namespace}].");
            $this->line('Make sure the namespace is loaded via Composer autoload.');
            return self::FAILURE;
        }

        $this->info('Found <comment>' . count($classes) . '</comment> class(es). Generating diagram...');

        $isEloquent = $this->isEloquentMode();
        $appClasses = array_map(function (string $fqcn) use ($analyzer, $withVendor, $isEloquent): array {
            $this->line("  → <comment>{$fqcn}</comment>");
            return $analyzer->analyze($fqcn, $withVendor, isEloquentModel: $isEloquent);
        }, $classes);

        $vendorClasses = $withVendor ? $analyzer->buildVendorStubs($appClasses) : [];

        file_put_contents($output, $generator->generate($appClasses, $vendorClasses));

        $this->newLine();
        $this->info("✅ Diagram generated: <comment>{$output}</comment>");

        return self::SUCCESS;
    }
}
