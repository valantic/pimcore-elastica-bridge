<?php

namespace Valantic\ElasticaBridgeBundle\Repository;

use Valantic\ElasticaBridgeBundle\DocumentType\Index\IndexDocumentInterface;
use Valantic\ElasticaBridgeBundle\Service\BridgeHelper;

class IndexDocumentRepository
{
    /**
     * @var IndexDocumentInterface []
     */
    protected array $indexDocuments;

    public function __construct(iterable $indexDocuments, BridgeHelper $bridgeHelper)
    {
        $this->indexDocuments = $bridgeHelper->iterableToArray($indexDocuments);
    }

    /**
     * @return IndexDocumentInterface []
     */
    public function all(): array
    {
        return $this->indexDocuments;
    }

    public function get(string $key): IndexDocumentInterface
    {
        return $this->indexDocuments[$key];
    }
}
