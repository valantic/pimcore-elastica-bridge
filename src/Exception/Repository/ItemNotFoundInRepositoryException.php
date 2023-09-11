<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Exception\Repository;

use Valantic\ElasticaBridgeBundle\Exception\BaseException;

class ItemNotFoundInRepositoryException extends BaseException
{
    public function __construct(string $key)
    {
        parent::__construct(sprintf('Item %s not found in repository', $key));
    }
}
