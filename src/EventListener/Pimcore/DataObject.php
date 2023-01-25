<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\EventListener\Pimcore;

use Pimcore\Event\Model\DataObjectEvent;

class DataObject extends AbstractListener
{
    public function added(DataObjectEvent $event): void
    {
        if (!self::$isEnabled) {
            return;
        }

        $this->ensurePresent($this->getFreshElement($event->getObject()));
    }

    public function updated(DataObjectEvent $event): void
    {
        if (!self::$isEnabled) {
            return;
        }

        $this->decideAction($this->getFreshElement($event->getObject()));
    }

    public function deleted(DataObjectEvent $event): void
    {
        if (!self::$isEnabled) {
            return;
        }

        $this->ensureMissing($this->getFreshElement($event->getObject()));
    }
}
