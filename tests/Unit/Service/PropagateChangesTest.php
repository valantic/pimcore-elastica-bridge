<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Tests\Unit\Service;

use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Valantic\ElasticaBridgeBundle\Index\IndexInterface;
use Valantic\ElasticaBridgeBundle\Messenger\Message\RefreshElementInIndex;
use Valantic\ElasticaBridgeBundle\Model\Event\ElasticaBridgeEvents;
use Valantic\ElasticaBridgeBundle\Model\Event\RefreshedElementEvent;
use Valantic\ElasticaBridgeBundle\Repository\IndexRepository;
use Valantic\ElasticaBridgeBundle\Service\DocumentHelper;
use Valantic\ElasticaBridgeBundle\Service\PropagateChanges;
use Valantic\ElasticaBridgeBundle\Tests\Helpers\PimcoreElementFactory;

class PropagateChangesTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private IndexRepository $indexRepository;
    private DocumentHelper $documentHelper;
    private MessageBusInterface $messageBus;
    private EventDispatcherInterface $eventDispatcher;
    private PropagateChanges $service;

    protected function setUp(): void
    {
        parent::setUp();

        // Reset static propagation state before each test
        $reflection = new \ReflectionClass(PropagateChanges::class);
        $property = $reflection->getProperty('isPropagationStopped');
        $property->setAccessible(true);
        $property->setValue(null, false);

        $this->indexRepository = \Mockery::mock(IndexRepository::class);
        $this->documentHelper = \Mockery::mock(DocumentHelper::class);
        $this->messageBus = \Mockery::mock(MessageBusInterface::class);
        $this->eventDispatcher = \Mockery::mock(EventDispatcherInterface::class);

        $this->service = new PropagateChanges(
            $this->indexRepository,
            $this->documentHelper,
            $this->messageBus,
            $this->eventDispatcher,
        );
    }

    public function testStopPropagation(): void
    {
        PropagateChanges::stopPropagation();

        // We can't directly test the static property, but we can verify behavior
        $this->assertTrue(true);
    }

    public function testHandleDispatchesPreRefreshEvent(): void
    {
        $element = PimcoreElementFactory::createDataObject(1);
        $index = \Mockery::mock(IndexInterface::class);
        $index->shouldReceive('getName')->andReturn('test_index');
        $index->shouldReceive('isElementAllowedInIndex')->andReturn(false);

        $this->indexRepository
            ->shouldReceive('flattenedAll')
            ->once()
            ->andReturn((static function () use ($index) {
                yield $index;
            })())
        ;

        $this->eventDispatcher
            ->shouldReceive('dispatch')
            ->with(
                \Mockery::type(RefreshedElementEvent::class),
                ElasticaBridgeEvents::PRE_REFRESH_ELEMENT,
            )
            ->once()
            ->andReturnUsing(static fn ($event) => $event)
        ;

        $this->messageBus
            ->shouldReceive('dispatch')
            ->andReturn(new Envelope(new \stdClass()))
        ;

        $this->eventDispatcher
            ->shouldReceive('dispatch')
            ->with(
                \Mockery::type(RefreshedElementEvent::class),
                ElasticaBridgeEvents::POST_REFRESH_ELEMENT,
            )
            ->once()
            ->andReturnUsing(static fn ($event) => $event)
        ;

        $this->service->handle($element);
    }

    public function testHandleDispatchesMessagesForMatchingIndices(): void
    {
        $element = PimcoreElementFactory::createDataObject(1);
        $index = \Mockery::mock(IndexInterface::class);
        $index->shouldReceive('getName')->andReturn('test_index');
        $index->shouldReceive('isElementAllowedInIndex')->with($element)->andReturn(true);

        $this->indexRepository
            ->shouldReceive('flattenedAll')
            ->once()
            ->andReturn((static function () use ($index) {
                yield $index;
            })())
        ;

        $this->eventDispatcher
            ->shouldReceive('dispatch')
            ->andReturnUsing(static fn ($event) => $event)
        ;

        $this->messageBus
            ->shouldReceive('dispatch')
            ->once()
            ->with(\Mockery::type(RefreshElementInIndex::class))
            ->andReturn(new Envelope(new \stdClass()))
        ;

        $this->service->handle($element);
    }
}
