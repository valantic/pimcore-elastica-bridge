<?php

namespace Valantic\ElasticaBridgeBundle\EventListener\Pimcore;

use Pimcore\Event\Model\DocumentEvent;

class Document extends AbstractListener
{
    public function added(DocumentEvent $event): void
    {
        if (!self::$isEnabled) {
            return;
        }

        $this->ensurePresent($event->getDocument());
    }

    public function updated(DocumentEvent $event): void
    {
        if (!self::$isEnabled) {
            return;
        }

        $this->decideAction($event->getDocument());
    }

    public function deleted(DocumentEvent $event): void
    {
        if (!self::$isEnabled) {
            return;
        }

        $this->ensureMissing($event->getDocument());
    }
}
