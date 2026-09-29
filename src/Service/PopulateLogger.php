<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Service;

use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class PopulateLogger
{
    /**
     * @var string[]
     */
    private array $messages = [];

    public function __construct(
        private readonly ConsoleOutputInterface $consoleOutput,
    ) {
    }

    /**
     * @phpstan-param ConsoleOutputInterface::VERBOSITY_* $level
     */
    public function setVerbosity(int $level): self
    {
        $this->consoleOutput->setVerbosity($level);

        return $this;
    }

    /**
     * @phpstan-param OutputInterface::VERBOSITY_* $verbosityLevel
     */
    public function log(string $indexName, string $message, int $verbosityLevel = OutputInterface::VERBOSITY_NORMAL): void
    {
        $this->messages[] = sprintf('%s: %s', $indexName, $message);
        $this->consoleOutput->writeln(sprintf('<info>%s</info>-> %s', $indexName, $message), $verbosityLevel);
    }

    /**
     * @return string[]
     */
    public function getLog(): array
    {
        return $this->messages;
    }
}
