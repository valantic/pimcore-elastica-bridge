<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Model\Event;

use Valantic\ElasticaBridgeBundle\Index\IndexInterface;

class PreSwitchIndexEvent extends AbstractPopulateEvent
{
    private int $remainingMessages;
    private bool $success = true;

    public function __construct(
        IndexInterface $index,
        public bool $initiateCooldown = false,
    ) {
        parent::__construct($index);
    }

    public function skipSwitch(): void
    {
        $this->success = false;
    }

    /** use this to set the remaining messages, either from fast storage or from the database */
    public function setRemainingMessages(int $remainingMessages): void
    {
        $this->remainingMessages = $remainingMessages;
    }

    public function getRemainingMessages(): ?int
    {
        return $this->remainingMessages ?? 0;
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }
}
