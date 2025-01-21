<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Factory;

use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\OutputInterface;

final class ConsoleOutputFactory
{
    public function create(): ConsoleOutput
    {
        return new ConsoleOutput(
            OutputInterface::VERBOSITY_VERBOSE
        );
    }
}
