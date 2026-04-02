<?php
namespace Quatrebarbes\Larchiclass\Tests\Fixtures;

abstract class AbstractBase
{
    abstract public function abstractMethod(): void;

    public function inheritedMethod(): string
    {
        return 'inherited';
    }
}
