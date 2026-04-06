<?php

namespace Quatrebarbes\Larchiclass\Tests;

use Orchestra\Testbench\TestCase;
use Quatrebarbes\Larchiclass\Analyzers\CallAnalyzer;
use Quatrebarbes\Larchiclass\Generators\PathUmlGenerator;
use Quatrebarbes\Larchiclass\LarchiServiceProvider;

// ---------------------------------------------------------------------------
// PathUmlGenerator Tests
// ---------------------------------------------------------------------------

class PathUmlGeneratorTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [LarchiServiceProvider::class];
    }

    // -------------------------------------------------------------------------
    // Envelope
    // -------------------------------------------------------------------------

    public function test_render_wraps_output_in_plantuml_tags(): void
    {
        $output = PathUmlGenerator::from([[]])->render();

        $this->assertStringStartsWith('@startuml', $output);
        $this->assertStringEndsWith('@enduml', trim($output));
    }

    public function test_render_empty_paths_produces_valid_skeleton(): void
    {
        $output = PathUmlGenerator::from([])->render();

        $this->assertStringContainsString('@startuml', $output);
        $this->assertStringContainsString('@enduml', $output);
        $this->assertStringContainsString('left to right direction', $output);
    }

    // -------------------------------------------------------------------------
    // Node declaration
    // -------------------------------------------------------------------------

    public function test_render_declares_all_nodes_from_paths(): void
    {
        $paths = [
            ['App\\Http\\Controllers\\UserController::index', 'App\\Services\\UserService::all'],
        ];

        $output = PathUmlGenerator::from($paths)->render();

        $this->assertStringContainsString('rectangle', $output);
        // Both nodes must appear as rectangle declarations
        $this->assertStringContainsString('index', $output);
        $this->assertStringContainsString('all', $output);
    }

    public function test_render_deduplicates_nodes_shared_across_paths(): void
    {
        $sharedNode = 'App\\Services\\UserService::save';
        $paths = [
            ['App\\Http\\Controllers\\UserController::store', $sharedNode],
            ['App\\Http\\Controllers\\UserController::update', $sharedNode],
        ];

        $output = PathUmlGenerator::from($paths)->render();

        // The shared node alias must appear exactly once as a rectangle declaration
        $alias = preg_replace('/[^a-zA-Z0-9_]/', '_', $sharedNode);
        $alias = preg_replace('/_+/', '_', $alias);
        $alias = trim($alias, '_');

        $declarationCount = substr_count($output, "as {$alias}");
        $this->assertSame(1, $declarationCount, 'Shared node should be declared only once');
    }

    // -------------------------------------------------------------------------
    // Labels
    // -------------------------------------------------------------------------

    public function test_label_uses_method_name_only_for_fqcn_nodes(): void
    {
        $paths = [['App\\Services\\OrderService::create']];

        $output = PathUmlGenerator::from($paths)->render();

        // The label in the rectangle should be just "create", not the full FQCN
        $this->assertMatchesRegularExpression('/"create"/', $output);
    }

    public function test_label_uses_function_name_for_free_functions(): void
    {
        $paths = [['App\\Helpers\\myHelperFunc()']];

        $output = PathUmlGenerator::from($paths)->render();

        $this->assertStringContainsString('myHelperFunc()', $output);
    }

    public function test_label_returns_raw_node_for_entry_points(): void
    {
        $paths = [['HTTP::GET /api/users']];

        $output = PathUmlGenerator::from($paths)->render();

        // The HTTP entry point label should appear verbatim
        $this->assertStringContainsString('GET /api/users', $output);
    }

    // -------------------------------------------------------------------------
    // Stereotypes
    // -------------------------------------------------------------------------

    public function test_stereotype_target_applied_when_target_set(): void
    {
        $paths = [['App\\Services\\Mailer::send']];

        $output = PathUmlGenerator::from($paths)
            ->withTarget('App\\Services\\Mailer::send')
            ->render();

        $this->assertStringContainsString('<<target>>', $output);
    }

    public function test_stereotype_target_is_case_insensitive(): void
    {
        $paths = [['App\\Services\\Mailer::send']];

        $output = PathUmlGenerator::from($paths)
            ->withTarget('app\\services\\mailer::SEND')
            ->render();

        $this->assertStringContainsString('<<target>>', $output);
    }

    public function test_stereotype_http_applied_to_http_entry_points(): void
    {
        $paths = [['HTTP::GET /api/users']];

        $output = PathUmlGenerator::from($paths)->render();

        $this->assertStringContainsString('<<HTTP>>', $output);
    }

    public function test_stereotype_cli_applied_to_cli_entry_points(): void
    {
        $paths = [['CLI::users:import']];

        $output = PathUmlGenerator::from($paths)->render();

        $this->assertStringContainsString('<<CLI>>', $output);
    }

    public function test_stereotype_cycle_applied_to_cycle_nodes(): void
    {
        $paths = [['[cycle] App\\Services\\Foo::bar']];

        $output = PathUmlGenerator::from($paths)->render();

        $this->assertStringContainsString('<<inherited>>', $output);
    }

    public function test_no_stereotype_for_regular_nodes(): void
    {
        $paths = [['App\\Services\\Foo::bar']];

        $output = PathUmlGenerator::from($paths)
            ->withTarget('App\\Other\\Class::method')
            ->render();

        // Should NOT contain any stereotype for this plain node
        $alias = 'App_Services_Foo_bar';
        $this->assertMatchesRegularExpression("/rectangle \"bar\" as {$alias}(?!<<)/", $output);
    }

    // -------------------------------------------------------------------------
    // Edges
    // -------------------------------------------------------------------------

    public function test_render_generates_arrow_between_consecutive_nodes(): void
    {
        $paths = [
            ['App\\Http\\Controllers\\UserController::index', 'App\\Services\\UserService::all'],
        ];

        $output = PathUmlGenerator::from($paths)->render();

        $this->assertStringContainsString('-->', $output);
    }

    public function test_render_uses_dotted_arrow_for_inherited_edges(): void
    {
        $child  = 'App\\Http\\Controllers\\ChildController::index';
        $parent = 'App\\Http\\Controllers\\BaseController::index';

        $paths = [[$parent, $child]];

        $output = PathUmlGenerator::from($paths)
            ->withInheritedLinks([$child => $parent])
            ->render();

        $this->assertStringContainsString('-[dotted]-|>', $output);
    }

    public function test_render_deduplicates_edges(): void
    {
        $a = 'App\\Services\\A::foo';
        $b = 'App\\Services\\B::bar';

        // Same edge appears in two paths
        $paths = [[$a, $b], [$a, $b]];

        $output = PathUmlGenerator::from($paths)->render();

        // Count occurrences of the arrow (should be exactly 1)
        $this->assertSame(1, substr_count($output, '-->'));
    }

    // -------------------------------------------------------------------------
    // Immutability (fluent API clones)
    // -------------------------------------------------------------------------

    public function test_with_target_does_not_mutate_original(): void
    {
        $original = PathUmlGenerator::from([['App\\Foo::bar']]);
        $modified = $original->withTarget('App\\Foo::bar');

        $originalOutput = $original->render();
        $modifiedOutput = $modified->render();

        $this->assertStringNotContainsString('App_Foo_bar<<target>>', $originalOutput);
        $this->assertStringContainsString('App_Foo_bar<<target>>', $modifiedOutput);
    }

    public function test_with_inherited_links_does_not_mutate_original(): void
    {
        $child  = 'App\\Child::method';
        $parent = 'App\\Parent::method';
        $paths  = [[$parent, $child]];

        $original = PathUmlGenerator::from($paths);
        $modified = $original->withInheritedLinks([$child => $parent]);

        $this->assertStringNotContainsString('-[dotted]-|>', $original->render());
        $this->assertStringContainsString('-[dotted]-|>', $modified->render());
    }

    // -------------------------------------------------------------------------
    // Alias sanitisation
    // -------------------------------------------------------------------------

    public function test_alias_strips_non_alphanumeric_characters(): void
    {
        // Nodes with special chars should produce clean aliases (no spaces, slashes, etc.)
        $paths = [['HTTP::GET /api/v1/users']];

        $output = PathUmlGenerator::from($paths)->render();

        // The output should not contain raw slashes or spaces inside the "as XXX" token
        preg_match_all('/as (\S+)/', $output, $matches);
        foreach ($matches[1] as $alias) {
            $this->assertMatchesRegularExpression('/^[a-zA-Z0-9_]+<<HTTP>>$/', $alias,
                "Alias '{$alias}' should only contain alphanumeric characters and underscores");
        }
    }

    // -------------------------------------------------------------------------
    // Skinparam / style lines
    // -------------------------------------------------------------------------

    public function test_render_includes_skinparam_block(): void
    {
        $output = PathUmlGenerator::from([[]])->render();

        $this->assertStringContainsString('skinparam', $output);
        $this->assertStringContainsString('BackgroundColor<<target>>', $output);
        $this->assertStringContainsString('BackgroundColor<<HTTP>>', $output);
        $this->assertStringContainsString('BackgroundColor<<CLI>>', $output);
    }
}

// ---------------------------------------------------------------------------
// CallAnalyzer Tests (unit-level, no filesystem access needed)
// ---------------------------------------------------------------------------

/**
 * Exposes private state of CallAnalyzer for white-box unit testing.
 * We use reflection to inject data directly into indexes and call
 * the methods that operate on them, without touching the filesystem.
 */
class CallAnalyzerTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [LarchiServiceProvider::class];
    }

    // -------------------------------------------------------------------------
    // Helpers — inject index state via reflection
    // -------------------------------------------------------------------------

    /**
     * Creates a CallAnalyzer instance with pre-populated indexes,
     * bypassing buildIndex() (which reads the filesystem).
     */
    private function makeAnalyzer(
        string $targetClass,
        string $targetMethod,
        array  $callIndex = [],
        array  $callIndexMeta = [],
        array  $parentIndex = [],
        array  $methodIndex = [],
        array  $inheritedLinks = [],
        array  $entryIndex = [],
    ): CallAnalyzer {
        $analyzer = CallAnalyzer::for($targetClass, $targetMethod);

        $ref = new \ReflectionClass($analyzer);

        $set = function (string $prop, mixed $value) use ($analyzer, $ref): void {
            $p = $ref->getProperty($prop);
            $p->setAccessible(true);
            $p->setValue($analyzer, $value);
        };

        $set('callIndex',     $callIndex);
        $set('callIndexMeta', $callIndexMeta);
        $set('parentIndex',   $parentIndex);
        $set('methodIndex',   $methodIndex);
        $set('inheritedLinks',$inheritedLinks);
        $set('entryIndex',    $entryIndex);
        // Mark as already indexed so buildIndex() is skipped
        $set('indexed', true);

        return $analyzer;
    }

    // -------------------------------------------------------------------------
    // paths()
    // -------------------------------------------------------------------------

    public function test_paths_returns_single_path_when_no_callers(): void
    {
        $analyzer = $this->makeAnalyzer('App\\Services\\Foo', 'bar');

        $paths = $analyzer->paths();

        // No callers → single path containing only the target itself
        $this->assertCount(1, $paths);
        $this->assertSame(['App\\Services\\Foo::bar'], $paths[0]);
    }

    public function test_paths_returns_path_ordered_root_to_target(): void
    {
        $callIndex = [
            'App\\Services\\Foo::bar' => ['App\\Http\\Controllers\\Ctrl::store'],
        ];

        $analyzer = $this->makeAnalyzer('App\\Services\\Foo', 'bar', $callIndex);

        $paths = $analyzer->paths();

        $this->assertCount(1, $paths);
        $this->assertSame(
            ['App\\Http\\Controllers\\Ctrl::store', 'App\\Services\\Foo::bar'],
            $paths[0]
        );
    }

    public function test_paths_returns_multiple_paths_for_multiple_callers(): void
    {
        $target    = 'App\\Services\\Foo::save';
        $callerA   = 'App\\Http\\Controllers\\CtrlA::store';
        $callerB   = 'App\\Http\\Controllers\\CtrlB::update';

        $callIndex = [
            $target => [$callerA, $callerB],
        ];

        $analyzer = $this->makeAnalyzer('App\\Services\\Foo', 'save', $callIndex);

        $paths = $analyzer->paths();

        $this->assertCount(2, $paths);

        $roots = array_map(fn($p) => $p[0], $paths);
        $this->assertContains($callerA, $roots);
        $this->assertContains($callerB, $roots);
    }

    public function test_paths_detects_cycle_and_prefixes_node(): void
    {
        // A -> B -> A forms a cycle
        $callIndex = [
            'App\\A::foo' => ['App\\B::bar'],
            'App\\B::bar' => ['App\\A::foo'],  // cycle back to A
        ];

        $analyzer = $this->makeAnalyzer('App\\A', 'foo', $callIndex);

        $paths = $analyzer->paths();

        $this->assertNotEmpty($paths);
        $hasCycle = false;
        foreach ($paths as $path) {
            foreach ($path as $node) {
                if (str_starts_with($node, '[cycle]')) {
                    $hasCycle = true;
                }
            }
        }
        $this->assertTrue($hasCycle, 'A cycle should produce a [cycle] node');
    }

    public function test_paths_target_method_is_normalised_to_lowercase(): void
    {
        // Targeting 'MyMethod' (mixed case) should match 'mymethod' in index
        $target    = 'App\\Services\\Foo::mymethod';
        $caller    = 'App\\Http\\Controllers\\Ctrl::action';
        $callIndex = [$target => [$caller]];

        $analyzer = $this->makeAnalyzer('App\\Services\\Foo', 'MyMethod', $callIndex);

        $paths = $analyzer->paths();

        $this->assertCount(1, $paths);
        $this->assertSame($caller, $paths[0][0]);
    }

    public function test_paths_leading_backslash_is_stripped_from_target_class(): void
    {
        $target    = 'App\\Services\\Foo::go';
        $caller    = 'App\\Http\\Ctrl::action';
        $callIndex = [$target => [$caller]];

        // Pass class with leading backslash
        $analyzer = $this->makeAnalyzer('\\App\\Services\\Foo', 'go', $callIndex);

        $paths = $analyzer->paths();

        $this->assertCount(1, $paths);
        $this->assertSame($caller, $paths[0][0]);
    }

    // -------------------------------------------------------------------------
    // callers()
    // -------------------------------------------------------------------------

    public function test_callers_returns_empty_array_when_no_callers(): void
    {
        $analyzer = $this->makeAnalyzer('App\\Services\\Foo', 'bar');

        $this->assertSame([], $analyzer->callers());
    }

    public function test_callers_returns_metadata_for_each_call_site(): void
    {
        $target = 'App\\Services\\Foo::bar';
        $caller = 'App\\Http\\Controllers\\Ctrl::store';

        $callIndex = [$target => [$caller]];
        $meta = [
            $target => [
                $caller => [
                    ['caller' => $caller, 'file' => '/app/Ctrl.php', 'line' => 42],
                ],
            ],
        ];

        $analyzer = $this->makeAnalyzer('App\\Services\\Foo', 'bar', $callIndex, $meta);

        $callers = $analyzer->callers();

        $this->assertCount(1, $callers);
        $this->assertSame($caller, $callers[0]['caller']);
        $this->assertSame('/app/Ctrl.php', $callers[0]['file']);
        $this->assertSame(42, $callers[0]['line']);
    }

    public function test_callers_returns_all_call_sites_for_same_caller(): void
    {
        $target = 'App\\Services\\Foo::bar';
        $caller = 'App\\Http\\Controllers\\Ctrl::store';

        $callIndex = [$target => [$caller]];
        $meta = [
            $target => [
                $caller => [
                    ['caller' => $caller, 'file' => '/app/Ctrl.php', 'line' => 10],
                    ['caller' => $caller, 'file' => '/app/Ctrl.php', 'line' => 20],
                ],
            ],
        ];

        $analyzer = $this->makeAnalyzer('App\\Services\\Foo', 'bar', $callIndex, $meta);

        $this->assertCount(2, $analyzer->callers());
    }

    // -------------------------------------------------------------------------
    // inheritedLinks()
    // -------------------------------------------------------------------------

    public function test_inherited_links_returns_injected_links(): void
    {
        $links = [
            'App\\Child::method' => 'App\\Parent::method',
        ];

        $analyzer = $this->makeAnalyzer(
            'App\\Parent', 'method',
            inheritedLinks: $links
        );

        $this->assertSame($links, $analyzer->inheritedLinks());
    }

    public function test_inherited_links_returns_empty_array_by_default(): void
    {
        $analyzer = $this->makeAnalyzer('App\\Services\\Foo', 'bar');

        $this->assertSame([], $analyzer->inheritedLinks());
    }

    // -------------------------------------------------------------------------
    // entryPoints()
    // -------------------------------------------------------------------------

    public function test_entry_points_returns_empty_when_no_http_or_cli_roots(): void
    {
        // Path starts with a plain class call, not an entry point node
        $callIndex = [
            'App\\Services\\Foo::bar' => ['App\\Http\\Controllers\\Ctrl::index'],
        ];

        $analyzer = $this->makeAnalyzer('App\\Services\\Foo', 'bar', $callIndex);

        // entryPoints() only extracts nodes starting with '[' and ending with ']'
        $this->assertSame([], $analyzer->entryPoints());
    }

    public function test_entry_points_extracts_labels_from_bracket_root_nodes(): void
    {
        $target  = 'App\\Services\\Foo::bar';
        $httpCtrl = 'App\\Http\\Controllers\\Ctrl::index';
        $httpLabel = '[HTTP GET /api/foo]';

        $callIndex = [
            $target   => [$httpCtrl],
            $httpCtrl => [$httpLabel],
        ];

        $analyzer = $this->makeAnalyzer('App\\Services\\Foo', 'bar', $callIndex);

        $entryPoints = $analyzer->entryPoints();

        $this->assertContains('HTTP GET /api/foo', $entryPoints);
    }

    public function test_entry_points_deduplicates_identical_labels(): void
    {
        $target    = 'App\\Services\\Foo::bar';
        $httpLabel = '[HTTP GET /api/foo]';

        // Two paths both arrive through the same entry point
        $callIndex = [
            $target   => ['App\\Ctrl1::action', 'App\\Ctrl2::action'],
            'App\\Ctrl1::action' => [$httpLabel],
            'App\\Ctrl2::action' => [$httpLabel],
        ];

        $analyzer = $this->makeAnalyzer('App\\Services\\Foo', 'bar', $callIndex);

        $entryPoints = $analyzer->entryPoints();

        $this->assertCount(1, array_filter($entryPoints, fn($ep) => $ep === 'HTTP GET /api/foo'));
    }

    // -------------------------------------------------------------------------
    // withinNamespace()
    // -------------------------------------------------------------------------

    public function test_within_namespace_returns_new_instance(): void
    {
        $analyzer = CallAnalyzer::for('App\\Services\\Foo', 'bar');
        $filtered = $analyzer->withinNamespace('App\\Http');

        $this->assertNotSame($analyzer, $filtered);
    }

    // -------------------------------------------------------------------------
    // Integration: PathUmlGenerator + CallAnalyzer output
    // -------------------------------------------------------------------------

    public function test_path_uml_generator_renders_full_call_chain(): void
    {
        $root   = 'HTTP::GET /api/users';
        $ctrl   = 'App\\Http\\Controllers\\UserController::index';
        $svc    = 'App\\Services\\UserService::all';

        $paths = [[$root, $ctrl, $svc]];

        $output = PathUmlGenerator::from($paths)
            ->withTarget($svc)
            ->render();

        $this->assertStringContainsString('@startuml', $output);
        $this->assertStringContainsString('@enduml', $output);
        $this->assertStringContainsString('<<HTTP>>', $output);
        $this->assertStringContainsString('<<target>>', $output);
        $this->assertStringContainsString('-->', $output);
    }

    public function test_path_uml_generator_marks_inherited_edge_as_dotted(): void
    {
        $child  = 'App\\Http\\Controllers\\ChildController::index';
        $parent = 'App\\Http\\Controllers\\BaseController::index';

        $paths = [[$child, $parent]];

        $output = PathUmlGenerator::from($paths)
            ->withInheritedLinks([$child => $parent])
            ->render();

        $this->assertStringContainsString('-[dotted]-|>', $output);
        $this->assertStringNotContainsString('-->', $output);
    }
}
