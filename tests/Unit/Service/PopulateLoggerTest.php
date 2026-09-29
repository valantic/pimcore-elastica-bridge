<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Tests\Unit\Service;

use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery\MockInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Valantic\ElasticaBridgeBundle\Service\PopulateLogger;

class PopulateLoggerTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private ConsoleOutputInterface&MockInterface $consoleOutput;

    private PopulateLogger $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->consoleOutput = \Mockery::mock(ConsoleOutputInterface::class);
        $this->logger = new PopulateLogger($this->consoleOutput);
    }

    public function testStartsEmpty(): void
    {
        $this->assertSame([], $this->logger->getLog());
    }

    public function testLogWritesToConsoleAndRecordsMessage(): void
    {
        $this->consoleOutput->shouldReceive('writeln')->once()->with('<info>products</info>-> hello', ConsoleOutputInterface::VERBOSITY_NORMAL);

        $this->logger->log('products', 'hello');

        $this->assertSame(['products: hello'], $this->logger->getLog());
    }

    public function testLogPassesVerbosityLevelButAlwaysRecordsMessage(): void
    {
        $this->consoleOutput->shouldReceive('writeln')->once()->with('<info>products</info>-> details', ConsoleOutputInterface::VERBOSITY_VERBOSE);

        $this->logger->log('products', 'details', ConsoleOutputInterface::VERBOSITY_VERBOSE);

        $this->assertSame(['products: details'], $this->logger->getLog());
    }

    public function testKeepsMessagesInOrder(): void
    {
        $this->consoleOutput->shouldReceive('writeln');

        $this->logger->log('products', 'first');
        $this->logger->log('categories', 'second');

        $this->assertSame(['products: first', 'categories: second'], $this->logger->getLog());
    }

    public function testSetVerbosityForwardsToConsoleOutput(): void
    {
        $this->consoleOutput->shouldReceive('setVerbosity')->once()->with(ConsoleOutputInterface::VERBOSITY_DEBUG);

        $this->assertSame($this->logger, $this->logger->setVerbosity(ConsoleOutputInterface::VERBOSITY_DEBUG));
    }
}
