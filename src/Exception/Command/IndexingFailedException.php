<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Exception\Command;

use Throwable;
use Valantic\ElasticaBridgeBundle\Exception\BaseException;

class IndexingFailedException extends BaseException
{
    public function __construct(?Throwable $previous)
    {
        parent::__construct('Indexing command failed', 0, $previous);
    }
}
