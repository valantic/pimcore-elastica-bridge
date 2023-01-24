<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\EventListener\Pimcore;

use Pimcore\Event\Model\AssetEvent;

class Asset extends AbstractListener
{
    public function added(AssetEvent $event): void
    {
        if (!self::$isEnabled) {
            return;
        }

        $this->ensurePresent($event->getAsset());
    }

    public function updated(AssetEvent $event): void
    {
        if (!self::$isEnabled) {
            return;
        }

        $this->decideAction($event->getAsset());
    }

    public function deleted(AssetEvent $event): void
    {
        if (!self::$isEnabled) {
            return;
        }

        $this->ensureMissing($event->getAsset());
    }
}
