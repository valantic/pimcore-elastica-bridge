<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Command;

use Pimcore\Model\Element\AbstractElement;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Lock\Key;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Valantic\ElasticaBridgeBundle\Constant\CommandConstants;
use Valantic\ElasticaBridgeBundle\Elastica\Client\ElasticsearchClient;
use Valantic\ElasticaBridgeBundle\Enum\IndexBlueGreenSuffix;
use Valantic\ElasticaBridgeBundle\Exception\Command\DocumentFailedException;
use Valantic\ElasticaBridgeBundle\Exception\Command\IndexingFailedException;
use Valantic\ElasticaBridgeBundle\Exception\Index\BlueGreenIndicesIncorrectlySetupException;
use Valantic\ElasticaBridgeBundle\Index\IndexInterface;
use Valantic\ElasticaBridgeBundle\Messenger\Handler\CreateDocumentHandler;
use Valantic\ElasticaBridgeBundle\Messenger\Handler\SwitchIndexHandler;
use Valantic\ElasticaBridgeBundle\Messenger\Message\CreateDocument;
use Valantic\ElasticaBridgeBundle\Messenger\Message\ReleaseIndexLock;
use Valantic\ElasticaBridgeBundle\Model\Event\CallbackEvent;
use Valantic\ElasticaBridgeBundle\Model\Event\ElasticaBridgeEvents;
use Valantic\ElasticaBridgeBundle\Repository\ConfigurationRepository;
use Valantic\ElasticaBridgeBundle\Repository\DocumentRepository;
use Valantic\ElasticaBridgeBundle\Repository\IndexRepository;
use Valantic\ElasticaBridgeBundle\Service\DocumentHelper;
use Valantic\ElasticaBridgeBundle\Service\LockService;
use Valantic\ElasticaBridgeBundle\Util\ElasticsearchResponse;

class Index extends BaseCommand
{
    private const ARGUMENT_INDEX = 'index';
    private const OPTION_DELETE = 'delete';
    private const OPTION_POPULATE = 'populate';
    private const OPTION_LOCK_RELEASE = 'lock-release';
    private const SYNC = 'sync';
    private const ASYNC = 'async';
    public static bool $isPopulating = false;
    public static ?bool $isAsync = null;
    private bool $async;

    public function __construct(
        private readonly IndexRepository $indexRepository,
        private readonly ElasticsearchClient $esClient,
        private readonly KernelInterface $kernel,
        private readonly LockService $lockService,
        private readonly MessageBusInterface $messageBus,
        private readonly DocumentRepository $documentRepository,
        private readonly DocumentHelper $documentHelper,
        private readonly ConfigurationRepository $configurationRepository,
        private readonly CreateDocumentHandler $createDocumentHandler,
        private readonly SwitchIndexHandler $switchIndexHandler,
        private readonly EventDispatcherInterface $eventDispatcher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName(CommandConstants::COMMAND_INDEX)
            ->setDescription('Ensures all the indices are present and populated.')
            ->addArgument(
                self::ARGUMENT_INDEX,
                InputArgument::IS_ARRAY | InputArgument::OPTIONAL,
                'Optional: indices to process. Defaults to all if empty'
            )
            ->addOption(
                self::OPTION_DELETE,
                'd',
                InputOption::VALUE_NONE,
                'Delete i.e. re-create existing indices'
            )
            ->addOption(
                self::OPTION_POPULATE,
                'p',
                InputOption::VALUE_NONE,
                'Populate indices'
            )
            ->addOption(
                self::OPTION_LOCK_RELEASE,
                'l',
                InputOption::VALUE_NONE,
                'Force all indexing locks to be released'
            )
            ->addOption(
                self::SYNC,
                's',
                InputOption::VALUE_NONE,
                'Force sync mode',
            )
            ->addOption(
                self::ASYNC,
                'a',
                InputOption::VALUE_NONE,
                'Force async mode',
            );
    }

    protected function initialize(InputInterface $input, OutputInterface $output): void
    {
        parent::initialize($input, $output);

        $sync = $input->getOption(self::SYNC) === true;
        $async = $input->getOption(self::ASYNC) === true;

        if ($sync && $async) {
            throw new \InvalidArgumentException('Cannot use both sync and async mode at the same time.');
        }

        self::$isAsync = $this->async = ($async || !($sync || !$this->configurationRepository->shouldPopulateAsync()));
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $skippedIndices = [];

        foreach ($this->indexRepository->flattenedAll() as $indexConfig) {
            if (
                is_array($this->input->getArgument(self::ARGUMENT_INDEX))
                && count($this->input->getArgument(self::ARGUMENT_INDEX)) > 0
                && !in_array($indexConfig->getName(), $this->input->getArgument(self::ARGUMENT_INDEX), true)
            ) {
                $skippedIndices[] = $indexConfig->getName();

                continue;
            }

            $cooldownKey = $this->lockService->getKey($indexConfig->getName(), 'cooldown');
            $cooldownLock = !$this->lockService->createLockFromKey($cooldownKey, ttl: 30, autorelease: true)->acquire();


            $key = $this->lockService->getIndexingKey($indexConfig);
            $lock = $this->lockService->createLockFromKey($key);
            $processLocked = !$lock->acquire();

            if ($processLocked) {
                $this->output->writeln(
                    sprintf(
                        "\n<error>Lock for %s is held by another process. %s (%s)</error>\n",
                        $indexConfig->getName(),
                        $key,
                        hash('sha256', (string) $key)
                    )
                );
            }

            if ($cooldownLock) {
                $lock->release();
                $this->output->writeln(
                    sprintf(
                        "\n<error>Cooldown for %s is active.</error>\n",
                        $indexConfig->getName(),
                    )
                );
            }

            if ($processLocked || $cooldownLock) {
                if ($this->input->getOption(self::OPTION_LOCK_RELEASE) === true) {
                    $lock->release();
                    $this->output->writeln(sprintf(
                        "\n<comment>Force-released %s lock for %s.</comment>\n",
                        $processLocked ? 'indexing' : 'cooldown',
                        $indexConfig->getName()
                    ));
                }

                if ($this->input->getOption(self::OPTION_LOCK_RELEASE) === false) {
                    continue;
                }
            }

            if ($this->async && $this->configurationRepository->getCooldown() > 0) {
                $this->output->writeln(
                    sprintf(
                        "\n<comment>Cooldown will be enabled for %s seconds.</comment>\n",
                        $this->configurationRepository->getCooldown(),
                    ),
                );
            }
            $this->processIndex($indexConfig, $key);

            if ($this->input->getOption(self::OPTION_POPULATE) !== true) {
                $lock->release();
            }
        }

        if (count($skippedIndices) > 0) {
            $this->output->writeln('');
            $this->output->writeln(
                sprintf('<info>Skipped the following indices: %s</info>', implode(', ', $skippedIndices))
            );
        }

        return self::SUCCESS;
    }

    private function processIndex(IndexInterface $indexConfig, Key $key): void
    {
        $this->output->writeln(sprintf('<info>Index: %s</info>', $indexConfig->getName()));

        $index = $this->esClient->getIndex($indexConfig->getName());
        $currentIndex = $index;
        $this->ensureCorrectIndexSetup($indexConfig);

        if ($indexConfig->usesBlueGreenIndices()) {
            $currentIndex = $indexConfig->getBlueGreenInactiveElasticaIndex();
        }

        $this->output->writeln('');

        if ($this->input->getOption(self::OPTION_POPULATE) !== true) {
            return;
        }

        if ($indexConfig->usesBlueGreenIndices()) {
            $currentIndex->delete();
            $currentIndex->create($indexConfig->getCreateArguments());
            $this->output->writeln('<comment>-> Re-created inactive blue/green index</comment>');
        }

        ['messagesDispatched' => $dispatchedMessages, 'listingCount' => $listingCount] = $this->populateIndex($indexConfig);

        $currentIndex->refresh();
        $indexCount = $currentIndex->count();

        if ($this->async) {
            $this->output->writeln('<comment>-> Indexing is done asynchronously</comment>');
            $this->output->writeln(sprintf('<comment>-> %d dispatched messages out of %d documents</comment>', $dispatchedMessages, $listingCount));
        } else {
            $this->output->writeln(sprintf('<comment>-> %d documents</comment>', $indexCount));
        }

        if (!$indexConfig->usesBlueGreenIndices()) {
            return;
        }

        $message = new ReleaseIndexLock($indexConfig->getName(), $key, switchIndex: true);

        if ($this->async) {
            $this->messageBus->dispatch($message);

            return;
        }

        $this->switchIndexHandler->__invoke($message);
    }

    /**
     * @return array{messagesDispatched: int, listingCount: int}
     */
    private function populateIndex(IndexInterface $indexConfig): array
    {
        self::$isPopulating = true;
        $messagesDispatched = 0;
        $blueGreenKey = $this->lockService->lockSwitchBlueGreen($indexConfig);
        $this->lockService->initializeProcessCount($indexConfig->getName());

        try {
            $allowedDocuments = $indexConfig->getAllowedDocuments();

            foreach ($allowedDocuments as $documentKey => $document) {
                $documentInstance = $this->documentRepository->get($document);
                $this->documentHelper->setTenantIfNeeded($documentInstance, $indexConfig);

                $listing = $documentInstance->getListingInstance($indexConfig);
                $listingCount = $listing->getTotalCount();
                ProgressBar::setFormatDefinition('custom', "%percent%%\t%remaining%\t%memory%\n%message%");

                $progressBar = new ProgressBar($this->output, $listingCount);

                $progressBar->setMessage($document);
                $progressBar->setFormat('custom');

                $batchSize = $indexConfig->getBatchSize();
                $numberOfBatches = (int) ceil($listingCount / $batchSize);
                $this->output->getFormatter()->setDecorated(true);
                $this->output->writeln('');

                if (
                    $listingCount > 10000
                    && !$indexConfig->shouldPopulateInSubprocesses()
                    && $this->kernel->getEnvironment() === 'dev'
                ) {
                    $this->output->writeln(
                        '<info>For large indices please consider to implement `shouldPopulateInSubprocesses` to prevent memory exhaustion.',
                    );
                    $numberOfBatches = 1;
                } else {
                    $this->output->writeln(sprintf(
                        '<info>Populating index %s with %d documents in %d subprocesses.',
                        $indexConfig::class,
                        $listingCount,
                        $numberOfBatches
                    ));
                }

                $batchNumber = 0;

                while ($batchNumber < $numberOfBatches) {
                    $listing->setOffset($batchNumber * $batchSize);
                    $listing->setLimit($batchSize);
                    $data = $listing->getData();

                    foreach ($data ?? [] as $key => $dataObject) {
                        $lastItem = false;
                        $cooldown = 0;

                        if (
                            $batchNumber === $numberOfBatches - 1
                            && $key === array_key_last($data ?? [])
                            && $documentKey === array_key_last($allowedDocuments)
                        ) {
                            $message = new ReleaseIndexLock($indexConfig->getName(), $blueGreenKey);
                            $this->messageBus->dispatch($message);
                            $lastItem = true;
                            $cooldown = $this->async ? $this->configurationRepository->getCooldown() : 0;
                        }

                        try {
                            $progressBar->advance();
                            $dataObjectId = $dataObject->getId();

                            if (!$documentInstance->shouldIndex($dataObject)) {
                                $dataObjectId = null;

                                if (!$lastItem) {
                                    continue;
                                }
                            }

                            $callback = $this->eventDispatcher->dispatch(new CallbackEvent(), ElasticaBridgeEvents::CALLBACK_EVENT);

                            $message = new CreateDocument(
                                $dataObjectId,
                                $dataObject::class,
                                $document,
                                $indexConfig->getName(),
                                $lastItem,
                                $cooldown,
                                $callback
                            );
                            $messagesDispatched++;
                            $this->lockService->messageDispatched($indexConfig->getName());

                            if ($this->async) {
                                $envelope = new Envelope($message, []);
                                $this->messageBus->dispatch($envelope);

                                continue;
                            }

                            $this->createDocumentHandler->__invoke($message);
                        } catch (\Throwable $throwable) {
                            $this->displayDocumentError($indexConfig, $document, $dataObject, $throwable);

                            if (!$this->configurationRepository->shouldSkipFailingDocuments()) {
                                throw new DocumentFailedException($throwable);
                            }
                        }
                    }

                    \Pimcore::collectGarbage();

                    $batchNumber++;
                }
                $progressBar->finish();
            }
        } catch (\Throwable $throwable) {
            $this->displayIndexError($indexConfig, $throwable);

            throw new IndexingFailedException($throwable);
        } finally {
            if (isset($documentInstance)) {
                $this->documentHelper->setTenantIfNeeded($documentInstance, $indexConfig);
            }
        }

        $this->output->writeln('');
        self::$isPopulating = false;

        return [
            'messagesDispatched' => $messagesDispatched,
            'listingCount' => $listingCount ?? 0,
        ];
    }

    private function ensureCorrectIndexSetup(IndexInterface $indexConfig): void
    {
        if ($indexConfig->usesBlueGreenIndices()) {
            $this->ensureCorrectBlueGreenIndexSetup($indexConfig);

            return;
        }

        $this->ensureCorrectSimpleIndexSetup($indexConfig);
    }

    private function ensureCorrectSimpleIndexSetup(IndexInterface $indexConfig): void
    {
        $index = $indexConfig->getElasticaIndex();

        if ($this->input->getOption(self::OPTION_DELETE) === true && $index->exists()) {
            $index->delete();
            $this->output->writeln('<comment>-> Deleted index</comment>');
        }

        if (!$index->exists()) {
            $index->create($indexConfig->getCreateArguments());
            $this->output->writeln('<comment>-> Created index</comment>');
        }
    }

    private function ensureCorrectBlueGreenIndexSetup(IndexInterface $indexConfig): void
    {
        $shouldDelete = $this->input->getOption(self::OPTION_DELETE) === true;

        $nonAliasIndex = $this->esClient->getIndex($indexConfig->getName());

        // In case an index with the same name as the blue/green alias exists, delete it
        if (
            $nonAliasIndex->exists()
            && !ElasticsearchResponse::getResponse($this->esClient->indices()->existsAlias(['name' => $indexConfig->getName()]))->asBool()
        ) {
            $nonAliasIndex->delete();
            $this->output->writeln('<comment>-> Deleted non-blue/green index to prepare for blue/green usage</comment>');
        }

        foreach (IndexBlueGreenSuffix::cases() as $suffix) {
            $name = $indexConfig->getName() . $suffix->value;
            $aliasIndex = $this->esClient->getIndex($name);

            if ($shouldDelete && $aliasIndex->exists()) {
                $aliasIndex->delete();
                $this->output->writeln('<comment>-> Deleted blue/green index with alias</comment>');
            }

            if (!$aliasIndex->exists()) {
                $aliasIndex->create($indexConfig->getCreateArguments());
                $this->output->writeln('<comment>-> Created blue/green index with alias</comment>');
            }
        }

        try {
            $indexConfig->getBlueGreenActiveSuffix();
        } catch (BlueGreenIndicesIncorrectlySetupException) {
            $this->esClient->getIndex($indexConfig->getName() . IndexBlueGreenSuffix::BLUE->value)
                ->addAlias($indexConfig->getName());
        }

        $this->output->writeln('<comment>-> Ensured indices are correctly set up with alias</comment>');
    }

    private function displayDocumentError(
        IndexInterface $indexConfig,
        string $document,
        AbstractElement $dataObject,
        \Throwable $throwable,
    ): void {
        $this->output->writeln('');
        $this->output->writeln(sprintf(
            '<fg=red;options=bold>Error while populating index %s, processing documents of type %s, last processed element ID %s.</>',
            $indexConfig::class,
            $document,
            $dataObject->getId()
        ));
        $this->displayThrowable($throwable);
    }

    private function displayIndexError(IndexInterface $indexConfig, \Throwable $throwable): void
    {
        $this->output->writeln('');
        $this->output->writeln(sprintf(
            '<fg=red;options=bold>Error while populating index %s.</>',
            $indexConfig::class,
        ));

        $this->displayThrowable($throwable);
    }
}
