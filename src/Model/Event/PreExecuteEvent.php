<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Model\Event;

use Valantic\ElasticaBridgeBundle\Index\IndexInterface;

class PreExecuteEvent extends AbstractPopulateEvent
{
    public const SOURCE_SCHEDULER = 1;
    public const SOURCE_CLI = 2;
    public const SOURCE_API = 3;

    public function __construct(
        IndexInterface $index,
        /** the process was started from cli instead of scheduler, errors will be reset */
        public readonly int $source,
    ) {
        parent::__construct($index);
    }
}
