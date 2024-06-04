<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Command;

use Elastica\Index as ElasticaIndex;
use Pimcore\Model\Element\AbstractElement;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Valantic\ElasticaBridgeBundle\Constant\CommandConstants;
use Valantic\ElasticaBridgeBundle\Exception\Command\DocumentFailedException;
use Valantic\ElasticaBridgeBundle\Index\IndexInterface;
use Valantic\ElasticaBridgeBundle\Repository\ConfigurationRepository;
use Valantic\ElasticaBridgeBundle\Repository\DocumentRepository;
use Valantic\ElasticaBridgeBundle\Repository\IndexRepository;
use Valantic\ElasticaBridgeBundle\Service\DocumentHelper;

class DoPopulateIndex extends BaseCommand
{
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
        $this->setName(CommandConstants::COMMAND_DO_POPULATE_INDEX)
            ->setHidden(true)
            ->setDescription('[INTERNAL]')
            ->addOption(CommandConstants::OPTION_CONFIG, mode: InputOption::VALUE_REQUIRED)
            ->addOption(CommandConstants::OPTION_INDEX, mode: InputOption::VALUE_REQUIRED)
            ->addOption(CommandConstants::OPTION_BATCH_NUMBER, mode: InputOption::VALUE_REQUIRED)
            ->addOption(CommandConstants::OPTION_LISTING_COUNT, mode: InputOption::VALUE_REQUIRED)
            ->addOption(CommandConstants::OPTION_DOCUMENT, mode: InputOption::VALUE_REQUIRED)
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $indexConfig = $this->getIndex();

        if (!$indexConfig instanceof IndexInterface) {
            return self::FAILURE;
        }

        return $this->populateIndex($indexConfig, $indexConfig->getBlueGreenInactiveElasticaIndex());
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
        ProgressBar::setFormatDefinition('custom', "%percent%%\t%remaining%\t%memory%\n%message%");

        $batchNumber = (int) $this->input->getOption(CommandConstants::OPTION_BATCH_NUMBER);
        $listingCount = (int) $this->input->getOption(CommandConstants::OPTION_LISTING_COUNT);

        $allowedDocuments = $indexConfig->getAllowedDocuments();
        $document = $this->input->getOption(CommandConstants::OPTION_DOCUMENT);

        if (!in_array($document, $allowedDocuments, true)) {
            return self::FAILURE;
        }

        $progressBar = new ProgressBar($this->output, $listingCount > 0 ? $listingCount : 1);
        $progressBar->setMessage($document);
        $progressBar->setFormat('custom');
        $progressBar->setProgress($batchNumber * $indexConfig->getBatchSize());

        if (!$indexConfig->shouldPopulateInSubprocesses()) {
            $numberOfBatches = ceil($listingCount / $indexConfig->getBatchSize());

            for ($batch = 0; $batch < $numberOfBatches; $batch++) {
                $exitCode = $this->doPopulateIndex($esIndex, $indexConfig, $progressBar, $document, $batch);

                if ($exitCode !== self::SUCCESS) {
                    return $exitCode;
                }
            }
        } else {
            return $this->doPopulateIndex($esIndex, $indexConfig, $progressBar, $document, $batchNumber);
        }

        return self::SUCCESS;
    }

    private function doPopulateIndex(
        ElasticaIndex $esIndex,
        IndexInterface $indexConfig,
        ProgressBar $progressBar,
        string $document,
        int $batchNumber,
    ): int {
        $documentInstance = $this->documentRepository->get($document);

        $this->documentHelper->setTenantIfNeeded($documentInstance, $indexConfig);

        $batchSize = $indexConfig->getBatchSize();

        $listing = $documentInstance->getListingInstance($indexConfig);
        $listing->setOffset($batchNumber * $batchSize);
        $listing->setLimit($batchSize);

        $esDocuments = [];

        foreach ($listing->getData() ?? [] as $dataObject) {
            try {
                if (!$documentInstance->shouldIndex($dataObject)) {
                    continue;
                }
                $progressBar->advance();

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

        if ($indexConfig->refreshIndexAfterEveryDocumentWhenPopulating()) {
            $esIndex->refresh();
        }

        return self::SUCCESS;
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
}
