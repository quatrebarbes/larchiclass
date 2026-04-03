<?php

namespace Quatrebarbes\Larchiclass\Tests\Fixtures;

use Quatrebarbes\Larchiclass\Tests\Fixtures\SampleEnum;

class SampleDependent
{
    public function getStatus(): SampleEnum
    {
        return SampleEnum::Active;
    }
}
