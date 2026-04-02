<?php

namespace Quatrebarbes\Larchiclass\Tests;

use Orchestra\Testbench\TestCase;
use Quatrebarbes\Larchiclass\Analyzers\ClassAnalyzer;
use Quatrebarbes\Larchiclass\LarchiServiceProvider;
use Quatrebarbes\Larchiclass\Tests\Fixtures\AbstractBase;
use Quatrebarbes\Larchiclass\Tests\Fixtures\SampleClass;
use Quatrebarbes\Larchiclass\Tests\Fixtures\SampleEnum;
use Quatrebarbes\Larchiclass\Tests\Fixtures\SampleInterface;
use Quatrebarbes\Larchiclass\Tests\Fixtures\SampleTrait;

class ClassAnalyzerTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [LarchiServiceProvider::class];
    }

    private function analyzer(): ClassAnalyzer
    {
        return new ClassAnalyzer();
    }

    // -------------------------------------------------------------------------
    // analyze() — identity fields
    // -------------------------------------------------------------------------

    public function test_analyze_returns_correct_fqcn_and_name(): void
    {
        $data = $this->analyzer()->analyze(SampleClass::class);

        $this->assertSame(SampleClass::class, $data['fqcn']);
        $this->assertSame('SampleClass', $data['name']);
        $this->assertSame('Quatrebarbes\Larchiclass\Tests\Fixtures', $data['namespace']);
    }

    public function test_analyze_regular_class_flags(): void
    {
        $data = $this->analyzer()->analyze(SampleClass::class);

        $this->assertFalse($data['isAbstract']);
        $this->assertFalse($data['isInterface']);
        $this->assertFalse($data['isTrait']);
        $this->assertFalse($data['isEnum']);
        $this->assertFalse($data['isEloquent']);
        $this->assertFalse($data['isVendor']);
    }

    public function test_analyze_abstract_class(): void
    {
        $data = $this->analyzer()->analyze(AbstractBase::class);

        $this->assertTrue($data['isAbstract']);
        $this->assertFalse($data['isInterface']);
    }

    public function test_analyze_interface(): void
    {
        $data = $this->analyzer()->analyze(SampleInterface::class);

        $this->assertTrue($data['isInterface']);
        $this->assertFalse($data['isAbstract']); // interfaces are NOT flagged isAbstract
    }

    public function test_analyze_trait(): void
    {
        $data = $this->analyzer()->analyze(SampleTrait::class);

        $this->assertTrue($data['isTrait']);
    }

    public function test_analyze_enum(): void
    {
        $data = $this->analyzer()->analyze(SampleEnum::class);

        $this->assertTrue($data['isEnum']);
    }

    // -------------------------------------------------------------------------
    // analyze() — parent / interfaces / traits
    // -------------------------------------------------------------------------

    public function test_analyze_detects_parent(): void
    {
        $data = $this->analyzer()->analyze(SampleClass::class);

        $this->assertSame(AbstractBase::class, $data['parent']);
    }

    public function test_analyze_detects_interfaces(): void
    {
        $data = $this->analyzer()->analyze(SampleClass::class);

        $this->assertContains(SampleInterface::class, $data['interfaces']);
    }

    public function test_analyze_detects_traits(): void
    {
        $data = $this->analyzer()->analyze(SampleClass::class);

        $this->assertContains(SampleTrait::class, $data['traits']);
    }

    public function test_analyze_no_parent_when_none(): void
    {
        $data = $this->analyzer()->analyze(SampleInterface::class);

        $this->assertNull($data['parent']);
        $this->assertEmpty($data['interfaces']);
    }

    // -------------------------------------------------------------------------
    // analyze() — properties
    // -------------------------------------------------------------------------

    public function test_analyze_extracts_own_properties_only(): void
    {
        $data  = $this->analyzer()->analyze(SampleClass::class);
        $names = array_column($data['properties'], 'name');

        // Own properties
        $this->assertContains('name', $names);
        $this->assertContains('count', $names);
        $this->assertContains('active', $names);
        $this->assertContains('staticProp', $names);

        // Inherited properties from AbstractBase must be absent
        $this->assertNotContains('inheritedProp', $names);
    }

    public function test_analyze_property_visibility(): void
    {
        $data  = $this->analyzer()->analyze(SampleClass::class);
        $props = array_column($data['properties'], 'visibility', 'name');

        $this->assertSame('public',    $props['name']);
        $this->assertSame('protected', $props['count']);
        $this->assertSame('private',   $props['active']);
    }

    public function test_analyze_static_property_flagged(): void
    {
        $data  = $this->analyzer()->analyze(SampleClass::class);
        $props = array_column($data['properties'], 'static', 'name');

        $this->assertTrue($props['staticProp']);
        $this->assertFalse($props['name']);
    }

    public function test_analyze_property_types(): void
    {
        $data  = $this->analyzer()->analyze(SampleClass::class);
        $props = array_column($data['properties'], 'type', 'name');

        $this->assertSame('string', $props['name']);
        $this->assertSame('int',    $props['count']);
        $this->assertSame('bool',   $props['active']);
    }

    // -------------------------------------------------------------------------
    // analyze() — methods
    // -------------------------------------------------------------------------

    public function test_analyze_extracts_own_methods_only(): void
    {
        $data    = $this->analyzer()->analyze(SampleClass::class);
        $methods = array_column($data['methods'], 'name');

        $this->assertContains('doSomething', $methods);
        $this->assertContains('protectedMethod', $methods);
        $this->assertContains('privateMethod', $methods);
        $this->assertContains('staticMethod', $methods);

        // Inherited from AbstractBase — must NOT appear
        $this->assertNotContains('inheritedMethod', $methods);
    }

    public function test_analyze_method_visibility(): void
    {
        $data    = $this->analyzer()->analyze(SampleClass::class);
        $methods = array_column($data['methods'], 'visibility', 'name');

        $this->assertSame('public',    $methods['doSomething']);
        $this->assertSame('protected', $methods['protectedMethod']);
        $this->assertSame('private',   $methods['privateMethod']);
    }

    public function test_analyze_static_method_flagged(): void
    {
        $data    = $this->analyzer()->analyze(SampleClass::class);
        $methods = array_column($data['methods'], 'static', 'name');

        $this->assertTrue($methods['staticMethod']);
        $this->assertFalse($methods['doSomething']);
    }

    public function test_analyze_method_return_type(): void
    {
        $data    = $this->analyzer()->analyze(SampleClass::class);
        $methods = array_column($data['methods'], 'return', 'name');

        $this->assertSame('string',  $methods['doSomething']);
        $this->assertSame('?string', $methods['protectedMethod']);
        $this->assertSame('void', $methods['privateMethod']);
    }

    public function test_analyze_method_params(): void
    {
        $data    = $this->analyzer()->analyze(SampleClass::class);
        $methods = array_column($data['methods'], 'params', 'name');

        $this->assertSame(['string $arg', '?int $opt'], $methods['protectedMethod']);
        $this->assertSame([], $methods['doSomething']);
    }

    // -------------------------------------------------------------------------
    // isVendorClass()
    // -------------------------------------------------------------------------

    public function test_vendor_class_is_detected(): void
    {
        $analyzer = $this->analyzer();

        $this->assertTrue($analyzer->isVendorClass(\Illuminate\Support\ServiceProvider::class));
    }

    public function test_own_class_is_not_vendor(): void
    {
        $analyzer = $this->analyzer();

        $this->assertFalse($analyzer->isVendorClass(SampleClass::class));
    }

    // -------------------------------------------------------------------------
    // buildVendorStubs()
    // -------------------------------------------------------------------------

    public function test_build_vendor_stubs_generates_stub_for_vendor_parent(): void
    {
        $classData = $this->analyzer()->analyze(SampleClass::class);
        // Manually point parent to a vendor class
        $classData['parent']     = \Illuminate\Support\ServiceProvider::class;
        $classData['withVendor'] = false;

        $stubs = $this->analyzer()->buildVendorStubs([$classData]);

        $this->assertNotEmpty($stubs);
        $fqcns = array_column($stubs, 'fqcn');
        $this->assertContains(\Illuminate\Support\ServiceProvider::class, $fqcns);

        $stub = $stubs[array_search(\Illuminate\Support\ServiceProvider::class, $fqcns)];
        $this->assertTrue($stub['isVendor']);
        $this->assertTrue($stub['isStub']);
        $this->assertEmpty($stub['properties']);
        $this->assertEmpty($stub['methods']);
    }

    public function test_build_vendor_stubs_deduplicates(): void
    {
        $classData = $this->analyzer()->analyze(SampleClass::class);
        $classData['parent']     = \Illuminate\Support\ServiceProvider::class;
        $classData['withVendor'] = false;

        $stubs = $this->analyzer()->buildVendorStubs([$classData, $classData]);

        $fqcns = array_column($stubs, 'fqcn');
        $this->assertCount(1, array_filter($fqcns, fn ($f) => $f === \Illuminate\Support\ServiceProvider::class));
    }

    public function test_build_vendor_stubs_skips_known_classes(): void
    {
        $parentData = $this->analyzer()->analyze(AbstractBase::class);
        $childData  = $this->analyzer()->analyze(SampleClass::class);

        $stubs = $this->analyzer()->buildVendorStubs([$parentData, $childData]);

        // AbstractBase is not a vendor class — no stub expected
        $fqcns = array_column($stubs, 'fqcn');
        $this->assertNotContains(AbstractBase::class, $fqcns);
    }
}
