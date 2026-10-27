<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Valantic\ElasticaBridgeBundle\Service\PopulationProgress;

class PopulationProgressTest extends TestCase
{
    public function testStartsAtZero(): void
    {
        $this->assertSame(0, (new PopulationProgress())->getRemaining());
    }

    public function testCountsDownFromTheStartedTotal(): void
    {
        $progress = new PopulationProgress();
        $progress->start(3);

        $progress->markProcessed();
        $progress->markProcessed();

        $this->assertSame(1, $progress->getRemaining());
    }

    public function testStartingAgainResetsTheCount(): void
    {
        $progress = new PopulationProgress();
        $progress->start(3);
        $progress->markProcessed();

        $progress->start(5);

        $this->assertSame(5, $progress->getRemaining());
    }
}
