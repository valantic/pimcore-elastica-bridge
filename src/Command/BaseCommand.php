<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Command;

use Pimcore\Console\AbstractCommand;

abstract class BaseCommand extends AbstractCommand
{
    protected function displayThrowable(\Throwable $throwable): void
    {
        $this->output->writeln('');
        $this->output->writeln(sprintf('In %s line %d', $throwable->getFile(), $throwable->getLine()));
        $this->output->writeln('');

        $this->output->writeln($throwable->getMessage());
        $this->output->writeln('');

        $this->output->writeln($throwable->getTraceAsString());
        $this->output->writeln('');
    }
}
