<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\EventListener\Pimcore;

use Pimcore\Event\Model\DocumentEvent;

class Document extends AbstractListener
{
    public function added(DocumentEvent $event): void
    {
        if (!self::$isEnabled) {
            return;
        }

        $this->ensurePresent($this->getFreshElement($event->getDocument()));
    }

    public function updated(DocumentEvent $event): void
    {
        if (!self::$isEnabled) {
            return;
        }

        $this->decideAction($this->getFreshElement($event->getDocument()));
    }

    public function deleted(DocumentEvent $event): void
    {
        if (!self::$isEnabled) {
            return;
        }

        $this->ensureMissing($this->getFreshElement($event->getDocument()));
    }
}
