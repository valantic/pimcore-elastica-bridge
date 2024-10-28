<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Exception\Index;

use Valantic\ElasticaBridgeBundle\Exception\BaseException;

class AlreadyInProgressException extends BaseException
{
    public const TYPE_COOLDOWN = 'cooldown';
    public const TYPE_INDEXING = 'indexing';
    public const TYPE_PROCESSING_MESSAGES = 'processing messages';
    public const TYPE_NO_DOCUMENTS = '0 documents';
    public const TYPE_PROCESSING = 'processing lock active';

    /**
     * @param self::TYPE_* $type
     */
    public function __construct(string $type)
    {
        parent::__construct(sprintf('Process not started (%s)', $type));
    }
}
