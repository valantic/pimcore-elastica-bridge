<?php

namespace Valantic\ElasticaBridgeBundle\EventListener\Pimcore;

use Pimcore\Event\Model\DataObjectEvent;

class DataObject extends AbstractListener
{
    public function added(DataObjectEvent $event): void
    {
        if (!self::$isEnabled) {
            return;
        }

        $this->ensurePresent($event->getObject());
    }

    public function updated(DataObjectEvent $event): void
    {
        if (!self::$isEnabled) {
            return;
        }

        $this->decideAction($event->getObject());
    }

    public function deleted(DataObjectEvent $event): void
    {
        if (!self::$isEnabled) {
            return;
        }

        $this->ensureMissing($event->getObject());
    }
}
