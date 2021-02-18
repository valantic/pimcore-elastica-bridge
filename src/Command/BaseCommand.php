<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Command;

use Pimcore\Console\AbstractCommand;

abstract class BaseCommand extends AbstractCommand
{
    protected const COMMAND_NAMESPACE = 'valantic:elastica-bridge:';
}
