<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Tests\Unit\Enum;

use PHPUnit\Framework\TestCase;
use Valantic\ElasticaBridgeBundle\Enum\PopulationSource;

class PopulationSourceTest extends TestCase
{
    public function testSchedulerCase(): void
    {
        $this->assertSame(1, PopulationSource::SCHEDULER->value);
    }

    public function testCliCase(): void
    {
        $this->assertSame(2, PopulationSource::CLI->value);
    }

    public function testApiCase(): void
    {
        $this->assertSame(3, PopulationSource::API->value);
    }

    public function testCasesCount(): void
    {
        $this->assertCount(3, PopulationSource::cases());
    }
}
