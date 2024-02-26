<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Command;

use Elastica\Index as ElasticaIndex;
use Pimcore\Model\Element\AbstractElement;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Valantic\ElasticaBridgeBundle\Exception\Command\DocumentFailedException;
use Valantic\ElasticaBridgeBundle\Exception\Command\IndexingFailedException;
use Valantic\ElasticaBridgeBundle\Index\IndexInterface;
use Valantic\ElasticaBridgeBundle\Repository\ConfigurationRepository;
use Valantic\ElasticaBridgeBundle\Repository\DocumentRepository;
use Valantic\ElasticaBridgeBundle\Repository\IndexRepository;
use Valantic\ElasticaBridgeBundle\Service\DocumentHelper;

class PopulateIndex extends BaseCommand
{
    private const OPTION_CONFIG = 'config';
    private const OPTION_INDEX = 'index';

    public function __construct(
        private readonly IndexRepository $indexRepository,
        private readonly DocumentRepository $documentRepository,
        private readonly DocumentHelper $documentHelper,
        private readonly ConfigurationRepository $configurationRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName(self::COMMAND_NAMESPACE . 'populate-index')
            ->setHidden(true)
            ->setDescription('[INTERNAL]')
            ->addOption(self::OPTION_CONFIG, mode: InputOption::VALUE_REQUIRED)
            ->addOption(self::OPTION_INDEX, mode: InputOption::VALUE_REQUIRED)
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $indexConfig = $this->getIndex();

        if (!$indexConfig instanceof IndexInterface) {
            return self::FAILURE;
        }

        $index = $indexConfig->getBlueGreenInactiveElasticaIndex();
        $this->populateIndex($indexConfig, $index);

        return self::SUCCESS;
    }

    private function getIndex(): ?IndexInterface
    {
        foreach ($this->indexRepository->flattenedAll() as $indexConfig) {
            if ($indexConfig->getName() === $this->input->getOption(self::OPTION_CONFIG)) {
                return $indexConfig;
            }
        }

        return null;
    }

    private function populateIndex(IndexInterface $indexConfig, ElasticaIndex $esIndex): void
    {
        ProgressBar::setFormatDefinition('custom', "%percent%%\t%remaining%\t%memory%\n%message%");

        $progressBar = new ProgressBar($this->output, 1);
        $progressBar->setMessage('');
        $progressBar->setFormat('custom');

        try {
            foreach ($indexConfig->getAllowedDocuments() as $document) {
                $progressBar->setProgress(0);
                $progressBar->setMessage($document);

                $documentInstance = $this->documentRepository->get($document);

                $this->documentHelper->setTenantIfNeeded($documentInstance, $indexConfig);

                $listingCount = $documentInstance->getListingInstance($indexConfig)->count();
                $progressBar->setMaxSteps($listingCount > 0 ? $listingCount : 1);
                $esDocuments = [];
                $numberOfBatches = ceil($listingCount / $indexConfig->getBatchSize());

                for ($batchNumber = 0; $batchNumber < $numberOfBatches; $batchNumber++) {
                    $listing = $documentInstance->getListingInstance($indexConfig);
                    $listing->setOffset($batchNumber * $indexConfig->getBatchSize());
                    $listing->setLimit($indexConfig->getBatchSize());

                    foreach ($listing->getData() ?? [] as $dataObject) {
                        try {
                            $progressBar->advance();

                            if (!$documentInstance->shouldIndex($dataObject)) {
                                continue;
                            }

                            $esDocuments[] = $this->documentHelper->elementToDocument($documentInstance, $dataObject);
                        } catch (\Throwable $throwable) {
                            $this->displayDocumentError($indexConfig, $document, $dataObject, $throwable);

                            if (!$this->configurationRepository->shouldSkipFailingDocuments()) {
                                throw new DocumentFailedException($throwable);
                            }
                        }
                    }

                    if (count($esDocuments) > 0) {
                        $esIndex->addDocuments($esDocuments);
                        $esDocuments = [];
                    }
                }

                if (count($esDocuments) > 0) {
                    $esIndex->addDocuments($esDocuments);
                }

                if ($indexConfig->refreshIndexAfterEveryDocumentWhenPopulating()) {
                    $esIndex->refresh();
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

        $progressBar->finish();
        $this->output->writeln('');
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

    private function displayThrowable(\Throwable $throwable): void
    {
        $this->output->writeln('');
        $this->output->writeln(sprintf('In %s line %d', $throwable->getFile(), $throwable->getLine()));
        $this->output->writeln('');

        $this->output->writeln($throwable->getMessage());
        $this->output->writeln('');

        $this->output->writeln($throwable->getTraceAsString());
        $this->output->writeln('');
    }
}
