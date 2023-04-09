<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Repository;

use Valantic\ElasticaBridgeBundle\Document\DocumentInterface;

/**
 * Used for typehinting. Contains an array of all DocumentInterface implementations.
 *
 * @see DocumentInterface
 */
class DocumentRepository extends AbstractRepository
{
    /**
     * @var DocumentInterface []
     */
    protected array $documents;

    public function __construct(iterable $documents)
    {
        $this->documents = $this->iterableToArray($documents);
    }

    /**
     * @return DocumentInterface []
     */
    public function all(): array
    {
        return $this->documents;
    }

    public function get(string $key): DocumentInterface
    {
        return $this->documents[$key];
    }
}
