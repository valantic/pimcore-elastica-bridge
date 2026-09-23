<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Command;

use Elastica\Index as ElasticaIndex;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Process\Process;
use Valantic\ElasticaBridgeBundle\Constant\CommandConstants;
use Valantic\ElasticaBridgeBundle\Exception\Command\IndexingFailedException;
use Valantic\ElasticaBridgeBundle\Index\IndexInterface;
use Valantic\ElasticaBridgeBundle\Repository\DocumentRepository;
use Valantic\ElasticaBridgeBundle\Repository\IndexRepository;
use Valantic\ElasticaBridgeBundle\Service\DocumentHelper;

class PopulateIndex extends BaseCommand
{
    public function __construct(
        private readonly IndexRepository $indexRepository,
        private readonly DocumentRepository $documentRepository,
        private readonly DocumentHelper $documentHelper,
        private readonly KernelInterface $kernel,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName(CommandConstants::COMMAND_POPULATE_INDEX)
            ->setHidden(true)
            ->setDescription('[INTERNAL]')
            ->addOption(CommandConstants::OPTION_CONFIG, mode: InputOption::VALUE_REQUIRED)
            ->addOption(CommandConstants::OPTION_INDEX, mode: InputOption::VALUE_REQUIRED)
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $indexConfig = $this->getIndex();

        if (!$indexConfig instanceof IndexInterface) {
            return self::FAILURE;
        }

        $index = $indexConfig->getBlueGreenInactiveElasticaIndex();

        return $this->populateIndex($indexConfig, $index);
    }

    private function getIndex(): ?IndexInterface
    {
        foreach ($this->indexRepository->flattenedAll() as $indexConfig) {
            if ($indexConfig->getName() === $this->input->getOption(CommandConstants::OPTION_CONFIG)) {
                return $indexConfig;
            }
        }

        return null;
    }

    private function populateIndex(IndexInterface $indexConfig, ElasticaIndex $esIndex): int
    {
        try {
            foreach ($indexConfig->getAllowedDocuments() as $document) {
                $documentInstance = $this->documentRepository->get($document);

                $this->documentHelper->setTenantIfNeeded($documentInstance, $indexConfig);

                $listingCount = $documentInstance->getListingInstance($indexConfig)->count();
                $numberOfBatches = ceil($listingCount / $indexConfig->getBatchSize());

                $this->output->getFormatter()->setDecorated(true);
                $this->output->writeln('');

                if (
                    !$indexConfig->shouldPopulateInSubprocesses()
                        && $this->kernel->getEnvironment() === 'dev'
                        && $listingCount > 10000
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
                        $numberOfBatches,
                    ));
                }

                for ($batchNumber = 0; $batchNumber < $numberOfBatches; $batchNumber++) {
                    $this->output->writeln('');
                    $this->output->writeln(sprintf('<comment>-> Populating index %s batch %d/%d.', $indexConfig::class, $batchNumber + 1, $numberOfBatches));
                    $this->output->writeln('');
                    $process = new Process(
                        [
                            'bin/console', CommandConstants::COMMAND_DO_POPULATE_INDEX,
                            '--' . CommandConstants::OPTION_CONFIG, $indexConfig->getName(),
                            '--' . CommandConstants::OPTION_INDEX, $esIndex->getName(),
                            '--' . CommandConstants::OPTION_BATCH_NUMBER, $batchNumber,
                            '--' . CommandConstants::OPTION_LISTING_COUNT, $listingCount,
                            '--' . CommandConstants::OPTION_DOCUMENT, $document,
                            ...array_filter([$this->output->isVerbose() ? '-v' : null,
                                $this->output->isVeryVerbose() ? '-vv' : null,
                                $this->output->isDebug() ? '-vvv' : null,
                            ]),
                        ],
                        $this->kernel->getProjectDir(),
                        timeout: null,
                    );

                    $exitCode = $process->run(function ($type, string|iterable $buffer): void {
                        if ($type === Process::ERR && $this->output instanceof ConsoleOutput) {
                            $this->output->getErrorOutput()->write($buffer);
                        } else {
                            $this->output->write($buffer);
                        }
                    });

                    if ($exitCode !== self::SUCCESS) {
                        return self::FAILURE;
                    }
                }
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

        return self::SUCCESS;
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
