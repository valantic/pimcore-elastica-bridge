<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Tests\Unit\EventListener\Messenger;

use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Valantic\ElasticaBridgeBundle\EventListener\Messenger\CreateDocumentFailedEvent;
use Valantic\ElasticaBridgeBundle\Index\IndexInterface;
use Valantic\ElasticaBridgeBundle\Messenger\Message\CreateDocumentMessage;
use Valantic\ElasticaBridgeBundle\Model\Event\ElasticaBridgeEvents;
use Valantic\ElasticaBridgeBundle\Model\Event\PostDocumentCreateEvent;
use Valantic\ElasticaBridgeBundle\Repository\IndexRepository;

class CreateDocumentFailedEventTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private EventDispatcher $eventDispatcher;
    private IndexInterface $index;

    /**
     * @var PostDocumentCreateEvent[]
     */
    private array $postEvents = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->index = \Mockery::mock(IndexInterface::class);
        $indexRepository = \Mockery::mock(IndexRepository::class);
        $indexRepository->shouldReceive('flattenedGet')->with('products')->andReturn($this->index);

        $this->eventDispatcher = new EventDispatcher();
        $this->eventDispatcher->addListener(ElasticaBridgeEvents::POST_DOCUMENT_CREATE, function (PostDocumentCreateEvent $event): void {
            $this->postEvents[] = $event;
        });
        $this->eventDispatcher->addSubscriber(new CreateDocumentFailedEvent($this->eventDispatcher, $indexRepository));
    }

    public function testFinalFailureIsDispatchedAsPostDocumentCreateEvent(): void
    {
        $this->eventDispatcher->dispatch($this->failedEvent(new CreateDocumentMessage([42], \stdClass::class, 'product_document', 'products')));

        $this->assertCount(1, $this->postEvents);
        $this->assertSame($this->index, $this->postEvents[0]->index);
        $this->assertSame(42, $this->postEvents[0]->elementId);
        $this->assertFalse($this->postEvents[0]->success);
        $this->assertFalse($this->postEvents[0]->willRetry);
    }

    public function testFinalFailureIsDispatchedForEveryElementInTheBatch(): void
    {
        $this->eventDispatcher->dispatch($this->failedEvent(new CreateDocumentMessage([1, 2, 3], \stdClass::class, 'product_document', 'products')));

        $this->assertSame([1, 2, 3], array_map(static fn (PostDocumentCreateEvent $event): ?int => $event->elementId, $this->postEvents));
    }

    public function testFailureThatWillBeRetriedIsIgnored(): void
    {
        $event = $this->failedEvent(new CreateDocumentMessage([42], \stdClass::class, 'product_document', 'products'));
        $event->setForRetry();

        $this->eventDispatcher->dispatch($event);

        $this->assertSame([], $this->postEvents);
    }

    public function testOtherMessagesAreIgnored(): void
    {
        $this->eventDispatcher->dispatch($this->failedEvent(new \stdClass()));

        $this->assertSame([], $this->postEvents);
    }

    private function failedEvent(object $message): WorkerMessageFailedEvent
    {
        return new WorkerMessageFailedEvent(new Envelope($message), 'elastica_bridge_populate', new \RuntimeException('failed'));
    }
}
