<?php

namespace Valantic\ElasticaBridgeBundle\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Valantic\ElasticaBridgeBundle\DocumentType\DocumentInterface;
use Valantic\ElasticaBridgeBundle\DocumentType\Index\IndexDocumentInterface;
use Valantic\ElasticaBridgeBundle\Elastica\Client\ElasticsearchClient;
use Valantic\ElasticaBridgeBundle\Index\IndexInterface;

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

    /**
     * Index constructor.
     * @param iterable<IndexInterface> $indices
     * @param iterable<DocumentInterface> $documents
     * @param iterable<IndexDocumentInterface> $indexDocuments
     * @param ElasticsearchClient $esClient
     */
    public function __construct(iterable $indices, iterable $documents, iterable $indexDocuments, ElasticsearchClient $esClient)
    {
        parent::__construct();
        $this->indices = $this->iterableToArray($indices);
        $this->documents = $this->iterableToArray($documents);
        $this->indexDocuments = $this->iterableToArray($indexDocuments);
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
