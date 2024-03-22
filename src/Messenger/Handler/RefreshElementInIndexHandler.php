<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Messenger\Handler;

use Pimcore\Model\Element\AbstractElement;
use Valantic\ElasticaBridgeBundle\Messenger\Message\RefreshElementInIndex;
use Valantic\ElasticaBridgeBundle\Repository\IndexRepository;
use Valantic\ElasticaBridgeBundle\Service\LockService;
use Valantic\ElasticaBridgeBundle\Service\PropagateChanges;

/**
 * @template TModel of AbstractElement
 *
 * @extends AbstractRefreshHandler<TModel>
 */
class RefreshElementInIndexHandler extends AbstractRefreshHandler
{
    public function __construct(
        private readonly PropagateChanges $propagateChanges,
        private readonly LockService $lockService,
        private readonly IndexRepository $indexRepository,
    ) {}

    public function __invoke(RefreshElementInIndex $message): void
    {
        $index = $this->indexRepository->flattenedGet($message->index);
        $element = $this->resolveElement($message);

        if (!$message->shouldTriggerEvents()) {
            PropagateChanges::disableTriggerEvents();
        }

        if ($index->usesBlueGreenIndices() && !$this->lockService->getIndexingLock($index)->acquire()) {
            $this->propagateChanges->handleIndex($element, $index, $index->getBlueGreenInactiveElasticaIndex());
        }

        $this->propagateChanges->handleIndex($element, $index);
    }
}
