<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Messenger\Handler;

use Pimcore\Model\Element\AbstractElement;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
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
        private readonly ConsoleOutputInterface $consoleOutput,
    ) {}

    public function __invoke(RefreshElementInIndex $message): void
    {
        $index = $this->indexRepository->flattenedGet($message->index);
        $element = $this->resolveElement($message);

        if ($message->isEventPropagationStopped()) {
            PropagateChanges::stopPropagation();
        }

        $this->consoleOutput->writeln(sprintf('Refreshing element %s in index %s', $element->getId(), $index->getName()), ConsoleOutputInterface::VERBOSITY_VERBOSE);

        $lock = $this->lockService->getIndexingLock($index, true);

        if ($index->usesBlueGreenIndices() && !$lock->acquire()) {
            $this->propagateChanges->handleIndex($element, $index, $index->getBlueGreenInactiveElasticaIndex());
        }

        $lock->release();

        $this->propagateChanges->handleIndex($element, $index);
    }
}
