<?php
namespace Quatrebarbes\Larchiclass\Tests\Fixtures;

class SampleClass extends AbstractBase implements SampleInterface
{
    use SampleTrait;

    public string $name;
    protected int $count = 0;
    private bool $active = true;
    public static string $staticProp = 'default';

    public function doSomething(): string
    {
        return $this->name;
    }

    public function abstractMethod(): void {}

    protected function protectedMethod(string $arg, ?int $opt = null): ?string
    {
        return null;
    }

    private function privateMethod(): void {}

    public static function staticMethod(): self
    {
        return new self();
    }
}
