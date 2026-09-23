<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Tests\Integration\Command;

use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery\MockInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\HelperSet;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Tester\CommandTester;
use Valantic\ElasticaBridgeBundle\Command\Cleanup;
use Valantic\ElasticaBridgeBundle\Model\CleanupResult;
use Valantic\ElasticaBridgeBundle\Service\CleanupService;

class CleanupCommandTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private CleanupService&MockInterface $cleanupService;
    private CommandTester $commandTester;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cleanupService = \Mockery::mock(CleanupService::class);

        $command = new Cleanup($this->cleanupService);
        $command->setHelperSet(new HelperSet([new QuestionHelper()]));
        $this->commandTester = new CommandTester($command);
    }

    public function testDryRunSkipsConfirmationAndListsWhatWouldBeDeleted(): void
    {
        $this->cleanupService
            ->shouldReceive('cleanUp')
            ->once()
            ->with(false, true)
            ->andReturn(new CleanupResult(true, ['products' => ['products_alias']], ['products'], []))
        ;

        $this->commandTester->execute(['--dry-run' => true]);

        $this->commandTester->assertCommandIsSuccessful();
        $display = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Would remove alias products_alias from index products', $display);
        $this->assertStringContainsString('Would delete index products', $display);
    }

    public function testDeclinedConfirmationDoesNotCleanUp(): void
    {
        $this->cleanupService->shouldNotReceive('cleanUp');

        $this->commandTester->setInputs(['n']);
        $this->commandTester->execute([]);

        $this->assertSame(Command::FAILURE, $this->commandTester->getStatusCode());
    }

    public function testForceDeletesAllIndicesAndReportsErrors(): void
    {
        $this->cleanupService
            ->shouldReceive('cleanUp')
            ->once()
            ->with(true, false)
            ->andReturn(new CleanupResult(false, [], ['other'], ['broken' => 'delete failed']))
        ;

        $this->commandTester->execute(['--all' => true, '--force' => true]);

        $this->commandTester->assertCommandIsSuccessful();
        $display = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Deleted index other', $display);
        $this->assertStringContainsString('delete failed', $display);
    }
}
