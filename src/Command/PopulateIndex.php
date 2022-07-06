<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Command;

use Elastica\Index as ElasticaIndex;
use Pimcore\Model\Element\AbstractElement;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;
use Valantic\ElasticaBridgeBundle\Elastica\Client\ElasticsearchClient;
use Valantic\ElasticaBridgeBundle\Exception\Command\IndexingFailedException;
use Valantic\ElasticaBridgeBundle\Index\IndexInterface;
use Valantic\ElasticaBridgeBundle\Repository\IndexDocumentRepository;
use Valantic\ElasticaBridgeBundle\Repository\IndexRepository;
use Valantic\ElasticaBridgeBundle\Service\DocumentHelper;

class PopulateIndex extends BaseCommand
{
    public const OPTION_CONFIG = 'config';
    public const OPTION_INDEX = 'index';
    protected ElasticsearchClient $esClient;

    public function __construct(
        protected IndexRepository $indexRepository,
        protected IndexDocumentRepository $indexDocumentRepository,
        protected DocumentHelper $documentHelper,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName(self::COMMAND_NAMESPACE . 'populate-index')
            ->setDescription('[INTERNAL]')
            ->addOption(self::OPTION_CONFIG, mode: InputOption::VALUE_REQUIRED)
            ->addOption(self::OPTION_INDEX, mode: InputOption::VALUE_REQUIRED)
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $indexConfig = null;
        foreach ($this->indexRepository->flattened() as $indexConfig) {
            if ($indexConfig->getName() === $this->input->getOption(self::OPTION_CONFIG)) {
                break;
            }
        }

        if (!$indexConfig instanceof IndexInterface) {
            return self::FAILURE;
        }

        $index = $indexConfig->getBlueGreenInactiveElasticaIndex();
        $this->populateIndex($indexConfig, $index);

        return self::SUCCESS;
    }

    protected function populateIndex(IndexInterface $indexConfig, ElasticaIndex $esIndex): void
    {
        ProgressBar::setFormatDefinition('custom', "%percent%%\t%remaining%\t%memory%\n%message%");

        $progressBar = new ProgressBar($this->output, 1);
        $progressBar->setMessage('');
        $progressBar->setFormat('custom');

        try {
            foreach ($indexConfig->getAllowedDocuments() as $indexDocument) {
                $progressBar->setProgress(0);
                $progressBar->setMessage($indexDocument);

                $indexDocumentInstance = $this->indexDocumentRepository->get($indexDocument);

                $this->documentHelper->setTenantIfNeeded($indexDocumentInstance, $indexConfig);

                $listingCount = $indexDocumentInstance->getListingInstance($indexConfig)->count();
                $progressBar->setMaxSteps($listingCount > 0 ? $listingCount : 1);
                $esDocuments = [];

                for ($batchNumber = 0; $batchNumber < ceil($listingCount / $indexConfig->getBatchSize()); $batchNumber++) {
                    $listing = $indexDocumentInstance->getListingInstance($indexConfig);
                    $listing->setOffset($batchNumber * $indexConfig->getBatchSize());
                    $listing->setLimit($indexConfig->getBatchSize());

                    foreach ($listing->getData() as $dataObject) {
                        $progressBar->advance();

                        if (!$indexDocumentInstance->shouldIndex($dataObject)) {
                            continue;
                        }

                        $esDocuments[] = $this->documentHelper->elementToIndexDocument($indexDocumentInstance, $dataObject);
                    }

                    if (count($esDocuments) > 0) {
                        $esIndex->addDocuments($esDocuments);
                        $esDocuments = [];
                    }
                }

                if (count($esDocuments) > 0) {
                    $esIndex->addDocuments($esDocuments);
                }

                if ($indexConfig->refreshIndexAfterEveryIndexDocumentWhenPopulating()) {
                    $esIndex->refresh();
                }
            }
        } catch (Throwable $throwable) {
            $this->output->writeln('');
            $this->output->writeln(sprintf(
                '<fg=red;options=bold>Error while populating index %s, processing documents of type %s, last processed element ID %s.</>',
                $indexConfig::class,
                $indexDocument ?? '(N/A)',
                isset($dataObject) && $dataObject instanceof AbstractElement ? $dataObject->getId() : '(N/A)'
            ));
            $this->output->writeln('');
            $this->output->writeln(sprintf('In %s line %d', $throwable->getFile(), $throwable->getLine()));
            $this->output->writeln('');
            if ($throwable->getMessage()) {
                $this->output->writeln($throwable->getMessage());
                $this->output->writeln('');
            }
            $this->output->writeln($throwable->getTraceAsString());
            $this->output->writeln('');

            throw new IndexingFailedException($throwable);
        } finally {
            if (isset($indexDocumentInstance)) {
                $this->documentHelper->setTenantIfNeeded($indexDocumentInstance, $indexConfig);
            }
        }

        $progressBar->finish();
        $this->output->writeln('');
    }
}
