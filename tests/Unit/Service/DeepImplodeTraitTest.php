<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Valantic\ElasticaBridgeBundle\Service\DeepImplodeTrait;

class DeepImplodeTraitTest extends TestCase
{
    private object $instance;

    protected function setUp(): void
    {
        parent::setUp();

        $this->instance = new class {
            use DeepImplodeTrait;

            public function publicDeepImplode(array $arr): string
            {
                return $this->deepImplode($arr);
            }

            public function publicDeepFlatten(array $arr): array
            {
                return $this->deepFlatten($arr);
            }
        };
    }

    public function testDeepImplodeWithSimpleArray(): void
    {
        $result = $this->instance->publicDeepImplode(['hello', 'world']);

        $this->assertSame("hello\nworld", $result);
    }

    public function testDeepImplodeWithNestedArray(): void
    {
        $result = $this->instance->publicDeepImplode([
            'first',
            ['second', 'third'],
            'fourth',
        ]);

        $this->assertSame("first\nsecond\nthird\nfourth", $result);
    }

    public function testDeepImplodeWithDeeplyNestedArray(): void
    {
        $result = $this->instance->publicDeepImplode([
            'a',
            ['b', ['c', 'd']],
            'e',
        ]);

        $this->assertSame("a\nb\nc\nd\ne", $result);
    }

    public function testDeepImplodeWithEmptyArray(): void
    {
        $result = $this->instance->publicDeepImplode([]);

        $this->assertSame('', $result);
    }

    public function testDeepFlattenWithSimpleArray(): void
    {
        $result = $this->instance->publicDeepFlatten(['one', 'two', 'three']);

        $this->assertSame(['one', 'two', 'three'], $result);
    }

    public function testDeepFlattenWithNestedArray(): void
    {
        $result = $this->instance->publicDeepFlatten([
            'a',
            ['b', 'c'],
            'd',
        ]);

        $this->assertSame(['a', 'b', 'c', 'd'], $result);
    }

    public function testDeepFlattenWithDeeplyNestedArray(): void
    {
        $result = $this->instance->publicDeepFlatten([
            ['a', ['b', ['c']]],
            'd',
        ]);

        $this->assertSame(['a', 'b', 'c', 'd'], $result);
    }

    public function testDeepFlattenPreservesNumericValues(): void
    {
        $result = $this->instance->publicDeepFlatten([1, [2, [3]], 4]);

        $this->assertSame([1, 2, 3, 4], $result);
    }
}
