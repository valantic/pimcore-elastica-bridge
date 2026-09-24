<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Tests\Integration\Command;

use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery\MockInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Valantic\ElasticaBridgeBundle\Command\Index;
use Valantic\ElasticaBridgeBundle\Exception\Index\PopulationNotStartedException;
use Valantic\ElasticaBridgeBundle\Index\IndexInterface;
use Valantic\ElasticaBridgeBundle\Messenger\Message\PopulateIndexMessage;
use Valantic\ElasticaBridgeBundle\Messenger\Message\ReleaseIndexLock;
use Valantic\ElasticaBridgeBundle\Messenger\Message\SwitchIndex;
use Valantic\ElasticaBridgeBundle\Model\Event\ElasticaBridgeEvents;
use Valantic\ElasticaBridgeBundle\Model\Event\PreExecuteEvent;
use Valantic\ElasticaBridgeBundle\Repository\IndexRepository;
use Valantic\ElasticaBridgeBundle\Service\PopulateIndexService;

class IndexCommandTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private PopulateIndexService&MockInterface $populateIndexService;
    private EventDispatcher $eventDispatcher;
    private CommandTester $tester;

    /**
     * @var list<object>
     */
    private array $dispatched = [];

    /**
     * @var array<string, IndexInterface>
     */
    private array $indices;

    protected function setUp(): void
    {
        parent::setUp();

        $this->indices = ['products' => $this->createIndex('products'), 'categories' => $this->createIndex('categories')];
        $indexRepository = \Mockery::mock(IndexRepository::class);
        $indexRepository->shouldReceive('flattenedAll')->andReturnUsing(function (): \Generator {
            yield from $this->indices;
        });

        $bus = \Mockery::mock(MessageBusInterface::class);
        $bus->shouldReceive('dispatch')->andReturnUsing(function (object $message): Envelope {
            $this->dispatched[] = $message;

            return new Envelope($message);
        });

        $this->populateIndexService = \Mockery::mock(PopulateIndexService::class);
        $this->populateIndexService->shouldReceive('setVerbosity')->andReturnSelf()->byDefault();
        $this->populateIndexService->shouldReceive('setShouldDelete')->andReturnSelf()->byDefault();
        $this->eventDispatcher = new EventDispatcher();

        $this->tester = new CommandTester(new Index($indexRepository, $bus, $this->populateIndexService, $this->eventDispatcher));
    }

    public function testCommandHasCorrectName(): void
    {
        $command = new Index(\Mockery::mock(IndexRepository::class), \Mockery::mock(MessageBusInterface::class), $this->populateIndexService, $this->eventDispatcher);

        $this->assertSame('valantic:elastica-bridge:index', $command->getName());
    }

    public function testPopulatesAllIndicesAndDispatchesGeneratedMessages(): void
    {
        $preExecuteEvents = [];
        $this->eventDispatcher->addListener(ElasticaBridgeEvents::PRE_EXECUTE, static function (PreExecuteEvent $event) use (&$preExecuteEvents): void {
            $preExecuteEvents[] = $event;
        });
        $switch = new SwitchIndex('products');
        $release = new ReleaseIndexLock('products');
        $this->populateIndexService->shouldReceive('setShouldDelete')->once()->with(false)->andReturnSelf();
        $this->populateIndexService
            ->shouldReceive('triggerSingleIndex')
            ->once()
            ->with($this->indices['products'], true, false, true)
            ->andReturnUsing(static function () use ($switch, $release): \Generator {
                yield new PopulateIndexMessage($switch);

                yield new PopulateIndexMessage($release);
            })
        ;
        $this->populateIndexService->shouldReceive('triggerSingleIndex')->once()->with($this->indices['categories'], true, false, true)->andReturnUsing(static fn (): \Generator => yield from []);

        $exitCode = $this->tester->execute(['--populate' => true]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertSame([$switch, $release], $this->dispatched);
        $this->assertCount(2, $preExecuteEvents);
        $this->assertSame(PreExecuteEvent::SOURCE_CLI, $preExecuteEvents[0]->source);
    }

    public function testPassesOptionsToPopulateService(): void
    {
        $this->populateIndexService->shouldReceive('setShouldDelete')->once()->with(true)->andReturnSelf();
        $this->populateIndexService
            ->shouldReceive('triggerSingleIndex')
            ->once()
            ->with($this->indices['products'], false, true, false)
            ->andReturnUsing(static fn (): \Generator => yield from [])
        ;

        $this->tester->execute(['index' => ['products'], '--delete' => true, '--ignore-locks' => true, '--cooldown' => true]);
    }

    public function testSkipsIndicesThatWereNotRequested(): void
    {
        $this->populateIndexService->shouldReceive('triggerSingleIndex')->once()->with($this->indices['categories'], true, false, true)->andReturnUsing(static fn (): \Generator => yield from []);

        $this->tester->execute(['index' => ['categories'], '--populate' => true]);

        $this->assertStringContainsString('Skipped the following indices: products', $this->tester->getDisplay());
    }

    public function testReportsIndicesStoppedByPreExecuteListener(): void
    {
        $this->eventDispatcher->addListener(ElasticaBridgeEvents::PRE_EXECUTE, static function (PreExecuteEvent $event): void {
            if ($event->index->getName() === 'products') {
                throw new PopulationNotStartedException(PopulationNotStartedException::TYPE_DISABLED);
            }
        });
        $this->populateIndexService->shouldReceive('triggerSingleIndex')->once()->with($this->indices['categories'], true, false, true)->andReturnUsing(static fn (): \Generator => yield from []);

        $this->tester->execute(['--populate' => true]);

        $this->assertStringContainsString('Failed disabled: products', $this->tester->getDisplay());
    }

    public function testContinuesWithNextIndexWhenPopulationCannotStart(): void
    {
        $this->populateIndexService->shouldReceive('triggerSingleIndex')->once()->with($this->indices['products'], true, false, true)->andReturnUsing(static function (): \Generator {
            yield from [];

            throw new PopulationNotStartedException(PopulationNotStartedException::TYPE_COOLDOWN);
        });
        $release = new ReleaseIndexLock('categories');
        $this->populateIndexService->shouldReceive('triggerSingleIndex')->once()->with($this->indices['categories'], true, false, true)->andReturnUsing(static function () use ($release): \Generator {
            yield new PopulateIndexMessage($release);
        });

        $this->tester->execute(['--populate' => true]);

        $this->assertSame([$release], $this->dispatched);
    }

    private function createIndex(string $name): IndexInterface
    {
        $index = \Mockery::mock(IndexInterface::class);
        $index->shouldReceive('getName')->andReturn($name);

        return $index;
    }
}
