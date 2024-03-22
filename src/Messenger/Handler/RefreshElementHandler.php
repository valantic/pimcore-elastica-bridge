<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Messenger\Handler;

use Pimcore\Model\Element\AbstractElement;
use Valantic\ElasticaBridgeBundle\Messenger\Message\RefreshElement;
use Valantic\ElasticaBridgeBundle\Service\PropagateChanges;

/**
 * @template TModel of AbstractElement
 *
 * @extends AbstractRefreshHandler<TModel>
 */
class RefreshElementHandler extends AbstractRefreshHandler
{
    public function __construct(
        private readonly PropagateChanges $propagateChanges,
    ) {}

    public function __invoke(RefreshElement $message): void
    {
        $element = $this->resolveElement($message);

        if (!$message->shouldTriggerEvents()) {
            PropagateChanges::disableTriggerEvents();
        }

        $this->propagateChanges->handle($element);
    }
}
