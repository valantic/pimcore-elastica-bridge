<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Tests\Unit\Enum;

use PHPUnit\Framework\TestCase;
use Valantic\ElasticaBridgeBundle\Enum\IndexBlueGreenSuffix;

class IndexBlueGreenSuffixTest extends TestCase
{
    public function testBlueCase(): void
    {
        $this->assertSame('--blue', IndexBlueGreenSuffix::BLUE->value);
    }

    public function testGreenCase(): void
    {
        $this->assertSame('--green', IndexBlueGreenSuffix::GREEN->value);
    }

    public function testCasesCount(): void
    {
        $cases = IndexBlueGreenSuffix::cases();

        $this->assertCount(2, $cases);
    }

    public function testAllCasesAreUnique(): void
    {
        $values = array_map(static fn ($case) => $case->value, IndexBlueGreenSuffix::cases());

        $this->assertCount(2, array_unique($values));
    }
}
