<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Tests\Unit\Enum;

use PHPUnit\Framework\TestCase;
use Valantic\ElasticaBridgeBundle\Enum\ElementInIndexOperation;

class ElementInIndexOperationTest extends TestCase
{
    public function testInsertCase(): void
    {
        $case = ElementInIndexOperation::INSERT;

        $this->assertSame('INSERT', $case->name);
    }

    public function testUpdateCase(): void
    {
        $case = ElementInIndexOperation::UPDATE;

        $this->assertSame('UPDATE', $case->name);
    }

    public function testDeleteCase(): void
    {
        $case = ElementInIndexOperation::DELETE;

        $this->assertSame('DELETE', $case->name);
    }

    public function testNothingCase(): void
    {
        $case = ElementInIndexOperation::NOTHING;

        $this->assertSame('NOTHING', $case->name);
    }

    public function testCasesCount(): void
    {
        $cases = ElementInIndexOperation::cases();

        $this->assertCount(4, $cases);
    }

    public function testAllCasesAreUnique(): void
    {
        $names = array_map(static fn ($case) => $case->name, ElementInIndexOperation::cases());

        $this->assertCount(4, array_unique($names));
    }
}
