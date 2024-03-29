<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Exception\DocumentType\Index;

use Valantic\ElasticaBridgeBundle\Exception\BaseException;

class EditablePartiallyImplementedException extends BaseException
{
    public function __construct(string $message)
    {
        parent::__construct($message);
    }
}
