<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Service;

/**
 * Counts down the messages of the population that is running in this process, i.e. when processing synchronously.
 */
class PopulationProgress
{
    private int $remaining = 0;

    public function start(int $total): void
    {
        $this->remaining = $total;
    }

    public function markProcessed(): void
    {
        $this->remaining--;
    }

    public function getRemaining(): int
    {
        return $this->remaining;
    }
}
