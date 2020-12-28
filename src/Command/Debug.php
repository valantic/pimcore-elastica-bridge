<?php

namespace Valantic\ElasticaBridgeBundle\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Valantic\ElasticaBridgeBundle\DocumentType\DocumentInterface;
use Valantic\ElasticaBridgeBundle\DocumentType\Index\IndexDocumentInterface;
use Valantic\ElasticaBridgeBundle\Elastica\Client\ElasticsearchClient;
use Valantic\ElasticaBridgeBundle\Index\IndexInterface;
use Valantic\ElasticaBridgeBundle\Service\BridgeHelper;

class Debug extends BaseCommand
{
    /**
     * @var IndexInterface[]
     */
    protected array $indices;
    /**
     * @var DocumentInterface[]
     */
    protected array $documents;
    /**
     * @var IndexDocumentInterface[]
     */
    protected array $indexDocuments;
    protected ElasticsearchClient $esClient;
    protected BridgeHelper $bridgeHelper;

    /**
     * Index constructor.
     *
     * @param iterable<IndexInterface> $indices
     * @param iterable<DocumentInterface> $documents
     * @param iterable<IndexDocumentInterface> $indexDocuments
     * @param ElasticsearchClient $esClient
     * @param BridgeHelper $bridgeHelper
     */
    public function __construct(
        iterable $indices,
        iterable $documents,
        iterable $indexDocuments,
        ElasticsearchClient $esClient,
        BridgeHelper $bridgeHelper
    )
    {
        parent::__construct();
        $this->bridgeHelper = $bridgeHelper;
        $this->indices = $this->bridgeHelper->iterableToArray($indices);
        $this->documents = $this->bridgeHelper->iterableToArray($documents);
        $this->indexDocuments = $this->bridgeHelper->iterableToArray($indexDocuments);
        $this->esClient = $esClient;
    }

    protected function configure(): void
    {
        $this->setName(self::COMMAND_NAMESPACE . 'debug');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->output->writeln('Use ' . self::COMMAND_NAMESPACE . 'index instead');

        return 1;
    }
}
