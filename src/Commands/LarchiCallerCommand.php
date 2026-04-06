<?php

namespace Quatrebarbes\Larchiclass\Commands;

use Illuminate\Console\Command;
use Quatrebarbes\Larchiclass\Analyzers\CallAnalyzer;
use Quatrebarbes\Larchiclass\Generators\PathUmlGenerator;

class LarchiCallerCommand extends Command
{
    protected $signature = 'larchi:caller
        {target : "Full\Namespace\Class::method"}
        {--output=larchi-caller.puml : Output file path}';

    protected $description = 'Analyze all the function callers leading to a method.';

    public function handle(): int
    {
        $target = (string) $this->argument('target');
        $output = $this->option('output');

        $this->info("🔍 Analyzing function callers to: <comment>{$target}</comment>");
        
        [$targetClass, $targetMethod] = explode('::', $target);
        if (! $targetClass || ! $targetMethod) {
            $this->warn("Malformed target [{$target}].");
            return self::FAILURE;
        }

        $analyzer = CallAnalyzer::for($targetClass, $targetMethod);

        $uml = PathUmlGenerator::from($analyzer->paths())
            ->withInheritedLinks($analyzer->inheritedLinks())
            ->withTarget($target)
            ->render();
        file_put_contents($output, $uml);
        $this->info("✅ Diagram generated: <comment>{$output}</comment>");

        return self::SUCCESS;
    }

}