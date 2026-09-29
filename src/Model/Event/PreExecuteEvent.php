<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Model\Event;

use Valantic\ElasticaBridgeBundle\Enum\PopulationSource;
use Valantic\ElasticaBridgeBundle\Index\IndexInterface;

class PreExecuteEvent extends AbstractPopulateEvent
{
    public function __construct(
        IndexInterface $index,
        /** where the population was triggered from; a CLI start resets errors */
        public readonly PopulationSource $source,
    ) {
        parent::__construct($index);
    }
}
