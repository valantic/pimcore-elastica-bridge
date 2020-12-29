<?php

namespace Valantic\ElasticaBridgeBundle\Command;

use Elastica\Index as ElasticaIndex;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Valantic\ElasticaBridgeBundle\Elastica\Client\ElasticsearchClient;
use Valantic\ElasticaBridgeBundle\Index\IndexInterface;
use Valantic\ElasticaBridgeBundle\Repository\IndexDocumentRepository;
use Valantic\ElasticaBridgeBundle\Repository\IndexRepository;
use Valantic\ElasticaBridgeBundle\Service\DocumentHelper;

class Index extends BaseCommand
{
    protected const ARGUMENT_INDEX = 'index';
    protected const OPTION_NO_DELETE = 'no-delete';
    protected const OPTION_NO_POPULATE = 'no-populate';
    protected const OPTION_NO_CHECK = 'no-check';
    protected ElasticsearchClient $esClient;
    protected DocumentHelper $documentHelper;
    protected IndexRepository $indexRepository;
    protected IndexDocumentRepository $indexDocumentRepository;

    public function __construct(
        IndexRepository $indexRepository,
        IndexDocumentRepository $indexDocumentRepository,
        ElasticsearchClient $esClient,
        DocumentHelper $documentHelper
    )
    {
        parent::__construct();
        $this->esClient = $esClient;
        $this->documentHelper = $documentHelper;
        $this->indexRepository = $indexRepository;
        $this->indexDocumentRepository = $indexDocumentRepository;
    }

    protected function configure(): void
    {
        $this->setName(self::COMMAND_NAMESPACE . 'index')
            ->setDescription('Ensures all the indices are present and populated.')
            ->addArgument(
                self::ARGUMENT_INDEX,
                InputArgument::IS_ARRAY | InputArgument::OPTIONAL,
                'Optional: indices to process. Defaults to all if empty'
            )
            ->addOption(
                self::OPTION_NO_DELETE,
                'd',
                InputOption::VALUE_NONE,
                'Do not delete i.e. re-create existing indices'
            )
            ->addOption(
                self::OPTION_NO_POPULATE,
                'p',
                InputOption::VALUE_NONE,
                'Do not populate created indices'
            )
            ->addOption(
                self::OPTION_NO_CHECK,
                'c',
                InputOption::VALUE_NONE,
                'Do not perform post-populate checks; implied with --' . self::OPTION_NO_POPULATE
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        foreach ($this->indexRepository->all() as $indexConfig) {
            $this->output->writeln('Index: ' . $indexConfig->getName());
            if (
                !empty($this->input->getArgument(self::ARGUMENT_INDEX)) &&
                !in_array($indexConfig->getName(), $this->input->getArgument(self::ARGUMENT_INDEX), true)
            ) {
                $this->output->writeln('> Skipped');
                continue;
            }

            $index = $this->esClient->getIndex($indexConfig->getName());

            if (!$this->input->getOption(self::OPTION_NO_DELETE)) {
                $this->ensureIndexExists($index, $indexConfig);
            }

            if (!$this->input->getOption(self::OPTION_NO_POPULATE)) {
                $this->populateIndex($indexConfig, $index);

                $index->refresh();
                $indexCount = $index->count();
                $this->output->writeln('> ' . $indexCount . ' documents');

                if ($indexCount > 0 && !$this->input->getOption(self::OPTION_NO_CHECK)) {
                    $this->checkRandomDocument($index, $indexConfig);
                }
            }
        }

        return 0;
    }

    protected function populateIndex(IndexInterface $indexConfig, ElasticaIndex $index): void
    {
        foreach ($indexConfig->getAllowedDocuments() as $indexDocument) {
            $indexDocumentInstance = $this->indexDocumentRepository->get($indexDocument);
            $listing = $indexDocumentInstance->getListingInstance($indexConfig);
            $listingCount = $listing->count();
            $esDocuments = [];
            for ($batchNumber = 0; $batchNumber < ceil($listingCount / $indexConfig->getBatchSize()); $batchNumber++) {
                $listing->setOffset($batchNumber * $indexConfig->getBatchSize());
                $listing->setLimit($indexConfig->getBatchSize());
                foreach ($listing as $dataObject) {
                    if (!$indexDocumentInstance->shouldIndex($dataObject)) {
                        continue;
                    }
                    $esDocuments[] = $this->documentHelper->elementToIndexDocument($indexDocumentInstance, $dataObject);
                    if (count($esDocuments) > $indexConfig->getBatchSize()) {
                        $index->addDocuments($esDocuments);
                        $esDocuments = [];
                    }
                }
            }
            if (count($esDocuments) > 0) {
                $index->addDocuments($esDocuments);
            }
        }
    }

    protected function ensureIndexExists(ElasticaIndex $index, IndexInterface $indexConfig): void
    {
        if ($index->exists()) {
            $index->delete();
            $this->output->writeln('> Deleted index');
        }
        $index->create($indexConfig->getCreateArguments());
        $this->output->writeln('> Created index');
    }

    protected function checkRandomDocument(ElasticaIndex $index, IndexInterface $indexConfig): void
    {
        $esDocs = $index->search();
        $esDoc = $esDocs[rand(0, $esDocs->count() - 1)]->getDocument();
        $indexDocumentInstance = $indexConfig->getIndexDocumentInstance($esDoc);
        $this->output->writeln(sprintf('> ES %s -> Pimcore %s', $esDoc->getId(), $indexDocumentInstance ? $indexDocumentInstance->getPimcoreElement($esDoc)->getId() : 'FAILED'));
    }
}
