<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Exception\Command;

use Valantic\ElasticaBridgeBundle\Exception\BaseException;

class DocumentFailedException extends BaseException
{
    public function __construct(?\Throwable $previous)
    {
        parent::__construct('Indexing document failed', 0, $previous);
    }
}
