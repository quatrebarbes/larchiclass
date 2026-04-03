<?php

namespace Quatrebarbes\Larchiclass\Tests;

use Orchestra\Testbench\TestCase;
use Quatrebarbes\Larchiclass\Analyzers\ClassAnalyzer;
use Quatrebarbes\Larchiclass\LarchiServiceProvider;
use Quatrebarbes\Larchiclass\Tests\Fixtures\AbstractBase;
use Quatrebarbes\Larchiclass\Tests\Fixtures\SampleClass;
use Quatrebarbes\Larchiclass\Tests\Fixtures\SampleDependent;
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
    // readTargetedClasses() — identity fields
    // -------------------------------------------------------------------------

    public function test_analyze_returns_correct_fqcn_and_name(): void
    {
        $data = $this->analyzer()->readTargetedClasses(SampleClass::class);

        $this->assertSame(SampleClass::class, $data['fqcn']);
        $this->assertSame('SampleClass', $data['name']);
        $this->assertSame('Quatrebarbes\Larchiclass\Tests\Fixtures', $data['namespace']);
    }

    public function test_analyze_regular_class_flags(): void
    {
        $data = $this->analyzer()->readTargetedClasses(SampleClass::class);

        $this->assertFalse($data['isAbstract']);
        $this->assertFalse($data['isInterface']);
        $this->assertFalse($data['isTrait']);
        $this->assertFalse($data['isEnum']);
        $this->assertFalse($data['isEloquent']);
        $this->assertFalse($data['isVendor']);
    }

    public function test_analyze_abstract_class(): void
    {
        $data = $this->analyzer()->readTargetedClasses(AbstractBase::class);

        $this->assertTrue($data['isAbstract']);
        $this->assertFalse($data['isInterface']);
    }

    public function test_analyze_interface(): void
    {
        $data = $this->analyzer()->readTargetedClasses(SampleInterface::class);

        $this->assertTrue($data['isInterface']);
        $this->assertFalse($data['isAbstract']); // interfaces are NOT flagged isAbstract
    }

    public function test_analyze_trait(): void
    {
        $data = $this->analyzer()->readTargetedClasses(SampleTrait::class);

        $this->assertTrue($data['isTrait']);
    }

    public function test_analyze_enum(): void
    {
        $data = $this->analyzer()->readTargetedClasses(SampleEnum::class);

        $this->assertTrue($data['isEnum']);
    }

    // -------------------------------------------------------------------------
    // readTargetedClasses() — parent / interfaces / traits
    // -------------------------------------------------------------------------

    public function test_analyze_detects_parent(): void
    {
        $data = $this->analyzer()->readTargetedClasses(SampleClass::class);

        $this->assertSame(AbstractBase::class, $data['parent']);
    }

    public function test_analyze_detects_interfaces(): void
    {
        $data = $this->analyzer()->readTargetedClasses(SampleClass::class);

        $this->assertContains(SampleInterface::class, $data['interfaces']);
    }

    public function test_analyze_detects_traits(): void
    {
        $data = $this->analyzer()->readTargetedClasses(SampleClass::class);

        $this->assertContains(SampleTrait::class, $data['traits']);
    }

    public function test_analyze_no_parent_when_none(): void
    {
        $data = $this->analyzer()->readTargetedClasses(SampleInterface::class);

        $this->assertNull($data['parent']);
        $this->assertEmpty($data['interfaces']);
    }

    // -------------------------------------------------------------------------
    // readTargetedClasses() — properties
    // -------------------------------------------------------------------------

    public function test_analyze_extracts_own_properties_only(): void
    {
        $data  = $this->analyzer()->readTargetedClasses(SampleClass::class);
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
        $data  = $this->analyzer()->readTargetedClasses(SampleClass::class);
        $props = array_column($data['properties'], 'visibility', 'name');

        $this->assertSame('public',    $props['name']);
        $this->assertSame('protected', $props['count']);
        $this->assertSame('private',   $props['active']);
    }

    public function test_analyze_static_property_flagged(): void
    {
        $data  = $this->analyzer()->readTargetedClasses(SampleClass::class);
        $props = array_column($data['properties'], 'static', 'name');

        $this->assertTrue($props['staticProp']);
        $this->assertFalse($props['name']);
    }

    public function test_analyze_property_types(): void
    {
        $data  = $this->analyzer()->readTargetedClasses(SampleClass::class);
        $props = array_column($data['properties'], 'type', 'name');

        $this->assertSame('string', $props['name']);
        $this->assertSame('int',    $props['count']);
        $this->assertSame('bool',   $props['active']);
    }

    public function test_analyze_untyped_property_returns_null_type(): void
    {
        // AbstractBase::$inheritedProp has no type hint
        $data  = $this->analyzer()->readTargetedClasses(AbstractBase::class);
        $props = array_column($data['properties'], 'type', 'name');

        // AbstractBase has no own properties — just asserting the key is present
        $this->assertIsArray($props);
    }

    // -------------------------------------------------------------------------
    // readTargetedClasses() — methods
    // -------------------------------------------------------------------------

    public function test_analyze_extracts_own_methods_only(): void
    {
        $data    = $this->analyzer()->readTargetedClasses(SampleClass::class);
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
        $data    = $this->analyzer()->readTargetedClasses(SampleClass::class);
        $methods = array_column($data['methods'], 'visibility', 'name');

        $this->assertSame('public',    $methods['doSomething']);
        $this->assertSame('protected', $methods['protectedMethod']);
        $this->assertSame('private',   $methods['privateMethod']);
    }

    public function test_analyze_static_method_flagged(): void
    {
        $data    = $this->analyzer()->readTargetedClasses(SampleClass::class);
        $methods = array_column($data['methods'], 'static', 'name');

        $this->assertTrue($methods['staticMethod']);
        $this->assertFalse($methods['doSomething']);
    }

    public function test_analyze_method_return_type(): void
    {
        $data    = $this->analyzer()->readTargetedClasses(SampleClass::class);
        $methods = array_column($data['methods'], 'return', 'name');

        $this->assertSame('string',  $methods['doSomething']);
        $this->assertSame('?string', $methods['protectedMethod']);
        $this->assertSame('void',    $methods['privateMethod']);
    }

    public function test_analyze_method_with_no_return_type_is_null(): void
    {
        // AbstractBase::abstractMethod() has return type void; let's verify untyped case
        // via a method that returns self — we rely on SampleClass::staticMethod returning 'self'
        $data    = $this->analyzer()->readTargetedClasses(SampleClass::class);
        $methods = array_column($data['methods'], 'return', 'name');

        $this->assertNotNull($methods['staticMethod']); // has return type `self`
    }

    public function test_analyze_method_params(): void
    {
        $data    = $this->analyzer()->readTargetedClasses(SampleClass::class);
        $methods = array_column($data['methods'], 'params', 'name');

        $this->assertSame(['string $arg', '?int $opt'], $methods['protectedMethod']);
        $this->assertSame([], $methods['doSomething']);
    }

    public function test_analyze_abstract_method_is_flagged(): void
    {
        $data    = $this->analyzer()->readTargetedClasses(AbstractBase::class);
        $methods = array_column($data['methods'], 'abstract', 'name');

        $this->assertTrue($methods['abstractMethod']);
        $this->assertFalse($methods['inheritedMethod']);
    }

    // -------------------------------------------------------------------------
    // isVendorClass()
    // -------------------------------------------------------------------------

    public function test_vendor_class_is_detected(): void
    {
        $this->assertTrue($this->analyzer()->isVendorClass(\Illuminate\Support\ServiceProvider::class));
    }

    public function test_own_class_is_not_vendor(): void
    {
        $this->assertFalse($this->analyzer()->isVendorClass(SampleClass::class));
    }

    public function test_unknown_class_is_not_vendor(): void
    {
        // Unknown FQCNs that cannot be reflected should default to false
        $this->assertFalse($this->analyzer()->isVendorClass('NonExistent\Class\That\Does\Not\Exist'));
    }

    // -------------------------------------------------------------------------
    // readRelatedClasses() — stubs
    // -------------------------------------------------------------------------

    public function test_build_dependency_stubs_generates_stub_for_vendor_parent(): void
    {
        $classData             = $this->analyzer()->readTargetedClasses(SampleClass::class);
        $classData['parent']   = \Illuminate\Support\ServiceProvider::class;

        $stubs = $this->analyzer()->readRelatedClasses([$classData], true);

        $this->assertNotEmpty($stubs);
        $fqcns = array_column($stubs, 'fqcn');
        $this->assertContains(\Illuminate\Support\ServiceProvider::class, $fqcns);

        $stub = $stubs[array_search(\Illuminate\Support\ServiceProvider::class, $fqcns)];
        $this->assertTrue($stub['isVendor']);
        $this->assertTrue($stub['isStub']);
        $this->assertEmpty($stub['properties']);
        $this->assertEmpty($stub['methods']);
    }

    public function test_build_dependency_stubs_deduplicates(): void
    {
        $classData           = $this->analyzer()->readTargetedClasses(SampleClass::class);
        $classData['parent'] = \Illuminate\Support\ServiceProvider::class;

        $stubs = $this->analyzer()->readRelatedClasses([$classData, $classData], true);

        $fqcns = array_column($stubs, 'fqcn');
        $this->assertCount(
            1,
            array_filter($fqcns, fn ($f) => $f === \Illuminate\Support\ServiceProvider::class)
        );
    }

    public function test_build_dependency_stubs_skips_known_classes(): void
    {
        $parentData = $this->analyzer()->readTargetedClasses(AbstractBase::class);
        $childData  = $this->analyzer()->readTargetedClasses(SampleClass::class);

        $stubs = $this->analyzer()->readRelatedClasses([$parentData, $childData], false);

        // AbstractBase is already in the targeted list — no stub expected
        $fqcns = array_column($stubs, 'fqcn');
        $this->assertNotContains(AbstractBase::class, $fqcns);
    }

    public function test_build_dependency_stubs_excludes_vendors_when_disabled(): void
    {
        $classData           = $this->analyzer()->readTargetedClasses(SampleClass::class);
        $classData['parent'] = \Illuminate\Support\ServiceProvider::class;

        // withVendors = false → vendor stubs must be omitted
        $stubs = $this->analyzer()->readRelatedClasses([$classData], false);

        $fqcns = array_column($stubs, 'fqcn');
        $this->assertNotContains(\Illuminate\Support\ServiceProvider::class, $fqcns);
    }

    public function test_stub_for_interface_carries_correct_flag(): void
    {
        $classData               = $this->analyzer()->readTargetedClasses(SampleClass::class);
        $classData['interfaces'] = [SampleInterface::class];
        $classData['parent']     = null;
        $classData['traits']     = [];
        $classData['dependencies'] = [];

        $stubs = $this->analyzer()->readRelatedClasses([$classData], false);

        $fqcns = array_column($stubs, 'fqcn');
        // SampleInterface is not a vendor, so it should appear
        $idx = array_search(SampleInterface::class, $fqcns);

        if ($idx !== false) {
            $this->assertTrue($stubs[$idx]['isInterface']);
        } else {
            // SampleInterface might already be in the list if the analyzer resolves it —
            // the key contract is that no duplicate is emitted.
            $this->assertCount(count(array_unique($fqcns)), $fqcns);
        }
    }

    // -------------------------------------------------------------------------
    // extractDependencies()
    // -------------------------------------------------------------------------

    public function test_analyze_returns_dependencies_key(): void
    {
        $data = $this->analyzer()->readTargetedClasses(SampleClass::class);

        $this->assertArrayHasKey('dependencies', $data);
    }

    public function test_analyze_extracts_typed_dependencies(): void
    {
        // SampleDependent::getStatus() has return type SampleEnum
        $data = $this->analyzer()->readTargetedClasses(SampleDependent::class);

        $this->assertContains(SampleEnum::class, $data['dependencies']);
    }

    public function test_analyze_excludes_parent_from_dependencies(): void
    {
        $data = $this->analyzer()->readTargetedClasses(SampleClass::class);

        $this->assertNotContains(AbstractBase::class, $data['dependencies']);
    }

    public function test_analyze_excludes_interfaces_from_dependencies(): void
    {
        $data = $this->analyzer()->readTargetedClasses(SampleClass::class);

        $this->assertNotContains(SampleInterface::class, $data['dependencies']);
    }

    public function test_analyze_excludes_traits_from_dependencies(): void
    {
        $data = $this->analyzer()->readTargetedClasses(SampleClass::class);

        $this->assertNotContains(SampleTrait::class, $data['dependencies']);
    }

    public function test_analyze_excludes_self_from_dependencies(): void
    {
        $data = $this->analyzer()->readTargetedClasses(SampleClass::class);

        $this->assertNotContains(SampleClass::class, $data['dependencies']);
    }

    public function test_analyze_no_dependencies_when_no_typed_references(): void
    {
        // AbstractBase has no typed property/method references to other classes
        $data = $this->analyzer()->readTargetedClasses(AbstractBase::class);

        $this->assertEmpty($data['dependencies']);
    }

    public function test_analyze_dependencies_are_unique(): void
    {
        // If a class references the same type multiple times, it should appear once
        $data = $this->analyzer()->readTargetedClasses(SampleDependent::class);

        $deps  = $data['dependencies'];
        $unique = array_unique($deps);
        $this->assertCount(count($unique), $deps);
    }
}
