<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Model\Event;

use Symfony\Contracts\EventDispatcher\Event;

class CallbackEvent extends Event
{
    private ?string $eventName;
    private ?string $eventClass;
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
        return isset($this->eventName, $this->eventClass);
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
