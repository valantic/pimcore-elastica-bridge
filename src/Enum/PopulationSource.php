<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Enum;

enum PopulationSource: int
{
    case SCHEDULER = 1;

    case CLI = 2;

    case API = 3;
}
