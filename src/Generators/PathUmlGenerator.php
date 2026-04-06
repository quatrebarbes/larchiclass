<?php

namespace Quatrebarbes\Larchiclass\Generators;

/**
 * Converts the result of CallAnalyzer::paths() into a PlantUML diagram.
 *
 * Usage:
 *   $analyzer = CallAnalyzer::for('App\Service\Mailer', 'send');
 *
 *   $uml = PathUmlGenerator::from($analyzer->paths())
 *       ->withInheritedLinks($analyzer->inheritedLinks())
 *       ->withTarget('App\Service\Mailer::send')
 *       ->render();
 */
class PathUmlGenerator
{
    /** @var list<list<string>> */
    private readonly array $paths;

    private ?string $target        = null;
    /** @var array<string, string> child::method => parent::method */
    private array   $inheritedLinks = [];

    /** @param list<list<string>> $paths */
    private function __construct(array $paths)
    {
        $this->paths = $paths;
    }

    /** @param list<list<string>> $paths */
    public static function from(array $paths): self
    {
        return new self($paths);
    }

    /**
     * Inheritance links from CallAnalyzer::inheritedLinks().
     * Allows distinguishing inherited edges from explicit calls.
     *
     * @param array<string, string> $links
     */
    public function withInheritedLinks(array $links): self
    {
        $clone                 = clone $this;
        $clone->inheritedLinks = $links;
        return $clone;
    }

    /**
     * FQCN::method of the target method — will be highlighted with a distinct color.
     */
    public function withTarget(string $target): self
    {
        $clone         = clone $this;
        $clone->target = strtolower(ltrim($target, '\\'));
        return $clone;
    }

    // -- Rendering ---------------------------------------------------------

    public function render(): string
    {
        $lines = [
            '@startuml',
            '',
            'set separator _',
            'left to right direction',
            '',
            'skinparam defaultFontName Monospaced',
            'skinparam componentStyle rectangle',
            'skinparam ArrowThickness 2',
            'skinparam ArrowColor #52B788',
            'skinparam rectangle {',
            '    StereotypeFontSize 0.8',
            '    StereotypeFontStyle<<target>> normal',
            '    FontStyle<<target>> bold',
            '    BackgroundColor<<target>> #D8F3DC',
            '    BorderColor<<target>> #52B788',
            '    BackgroundColor<<HTTP>> #F9E3BE',
            '    BorderColor<<HTTP>> #E99C16',
            '    BackgroundColor<<CLI>> #F9E3BE',
            '    BorderColor<<CLI>> #E99C16',
            '}',
            '',
        ];

        // Collect all unique nodes
        $allNodes = [];
        foreach ($this->paths as $path) {
            foreach ($path as $node) {
                $allNodes[$node] = true;
            }
        }

        // Declare nodes
        foreach (array_keys($allNodes) as $node) {
            $stereotype = $this->stereotype($node);
            $alias      = $this->alias($node);
            $label      = $this->label($node);
            $lines[]    = "rectangle \"{$label}\" as {$alias}{$stereotype}";
        }

        $lines[] = '';

        // Collect unique edges
        $edges = [];
        foreach ($this->paths as $path) {
            $len = count($path);
            for ($i = 0; $i < $len - 1; $i++) {
                $to   = $path[$i];
                $from = $path[$i + 1];
                // Inherited edge: $from is a known entry of inheritedLinks
                // and $to is its value (child::m --> parent::m)
                $isInherited = isset($this->inheritedLinks[$from])
                    && $this->inheritedLinks[$from] === $to;
                $key         = $this->alias($from) . '->' . $this->alias($to);
                $edges[$key] = [$this->alias($from), $this->alias($to), $isInherited];
            }
        }

        foreach ($edges as [$callee, $caller, $isInherited]) {
            $arrow   = $isInherited ? '-[dotted]-|>' : '-->';
            $lines[] = "{$callee} {$arrow} {$caller}";
        }

        $lines[] = '';
        $lines[] = '@enduml';

        return implode("\n", $lines);
    }

    // -- Helpers -----------------------------------------------------------

    private function stereotype(string $node): string
    {
        if ($this->target !== null && strtolower(ltrim($node, '\\')) === $this->target) {
            return '<<target>>';
        }
        if (str_starts_with($node, '[cycle]')) {
            return '<<inherited>>';
        }
        if (str_starts_with($node, 'HTTP::')) {
            return '<<HTTP>>';
        }
        if (str_starts_with($node, 'CLI::')) {
            return '<<CLI>>';
        }
        return '';
    }

    private function alias(string $node): string
    {
        $res = $node;
        $res = preg_replace('/[^a-zA-Z0-9_]/', '_', $res);
        $res = preg_replace('/_+/', '_', $res);
        $res = preg_replace('/_$/', '', $res);
        $res = preg_replace('/^_/', '', $res);

        if (str_starts_with($node, 'HTTP::')) {
            $res = 'HTTP_' . preg_replace('/_+/', '', $res);
        }
        if (str_starts_with($node, 'CLI::')) {
            $res = 'CLI_' . preg_replace('/_+/', '', $res);
        }

        return $res;
    }

    private function label(string $node): string
    {
        // Extract only the method name after ::
        if (str_contains($node, '::')) {
            return explode('::', $node, 2)[1];
        }

        // Free function: App\Commands\myFunc() -> myFunc()
        if (preg_match('/(?:^|\\\\)([^\\\\]+\(\))$/', $node, $m)) {
            return $m[1];
        }

        return $node;
    }
}
