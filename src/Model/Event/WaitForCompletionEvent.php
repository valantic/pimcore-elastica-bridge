<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Model\Event;

use Valantic\ElasticaBridgeBundle\Index\IndexInterface;

class WaitForCompletionEvent extends AbstractPopulateEvent
{
    private const MAX_SLEEP_DURATION = 300;
    public int $maximumRetries = 3;
    public int $rescheduleIntervalSeconds = 60;
    private int $remainingMessages;
    private bool $success = true;

    private int $sleepDuration = 3;

    public function __construct(
        IndexInterface $index,
        /** the number of times the message has been retried internally */
        public readonly int $retries = 0,
        public readonly int $requeues = 0,
    ) {
        parent::__construct($index);
    }

    public function getSleepDuration(): int
    {
        return $this->sleepDuration;
    }

    public function setSleepDuration(int $sleepDuration): void
    {
        if ($sleepDuration < 0) {
            throw new \InvalidArgumentException('Sleep duration must be greater than or equal to 0');
        }

        if ($sleepDuration > self::MAX_SLEEP_DURATION) {
            throw new \InvalidArgumentException('Sleep duration must be less than or equal to ' . self::MAX_SLEEP_DURATION);
        }
        $this->sleepDuration = $sleepDuration;
    }

    public function setRescheduleIntervalSeconds(int $seconds): void
    {
        $this->rescheduleIntervalSeconds = $seconds;
    }

    public function setMaximumRetries(int $retries): void
    {
        if ($retries < 0) {
            throw new \InvalidArgumentException('Maximum retries must be greater than or equal to 0');
        }

        $this->maximumRetries = $retries;
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
