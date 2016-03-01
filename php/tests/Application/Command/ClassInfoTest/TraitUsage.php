<?php

namespace A;

trait FirstTrait
{
    protected $firstTraitProperty;

    protected function testAmbiguous()
    {

    }

    protected function test()
    {

    }
}

trait SecondTrait
{
    protected $secondTraitProperty;

    protected function testAmbiguous()
    {

    }
}

trait BaseTrait
{
    protected $baseTraitProperty;

    public function baseTraitMethod()
    {

    }
}

class BaseClass
{
    use BaseTrait;
}

class TestClass extends BaseClass
{
    use FirstTrait, SecondTrait {
        test as private test1;
        SecondTrait::testAmbiguous insteadof testAmbiguous;
    }
}
