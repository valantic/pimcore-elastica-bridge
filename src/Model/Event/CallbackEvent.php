<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Model\Event;

use Symfony\Contracts\EventDispatcher\Event;

class CallbackEvent extends Event
{
    private ?string $eventName = null;
    private ?string $eventClass = null;
    /**
     * @var array<int|string|object>
     */
    private array $parameters = [];

    /**
     * @param string $eventName
     * @param string $eventClass
     * @param array<int|string|object> $parameters
     *
     * @return void
     */
    public function setEvent(
        string $eventName,
        string $eventClass,
        array $parameters = [],
    ): void {
        $this->eventName = $eventName;
        $this->eventClass = $eventClass;
        $this->parameters = $parameters;
    }

    public function shouldCallEvent(): bool
    {
        return $this->eventName !== null && $this->eventClass !== null;
    }

    public function getEventName(): ?string
    {
        return $this->eventName;
    }

    /**
     * @return object
     */
    public function getEvent(): object
    {
        return new $this->eventClass(...$this->parameters);
    }
}
