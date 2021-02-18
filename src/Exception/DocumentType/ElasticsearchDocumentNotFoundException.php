<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Exception\DocumentType;

use Valantic\ElasticaBridgeBundle\Exception\BaseException;

class ElasticsearchDocumentNotFoundException extends BaseException
{
    public function __construct(?string $id)
    {
        parent::__construct(sprintf('Elasticsearch document with ID %s could not be found', $id), 0, null);
    }
}
