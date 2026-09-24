<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Tests\Unit\Messenger\Middleware;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Lock\Key;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Middleware\StackMiddleware;
use Symfony\Component\Messenger\Stamp\HandlerArgumentsStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Stamp\SentStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Transport\Sync\SyncTransport;
use Valantic\ElasticaBridgeBundle\Messenger\Message\CreateDocumentMessage;
use Valantic\ElasticaBridgeBundle\Messenger\Message\SwitchIndex;
use Valantic\ElasticaBridgeBundle\Messenger\Message\TriggerSingleIndexMessage;
use Valantic\ElasticaBridgeBundle\Messenger\Middleware\RetryCountMiddleware;
use Valantic\ElasticaBridgeBundle\Messenger\Middleware\SyncTransportMiddleware;

class HandlerArgumentsMiddlewareTest extends TestCase
{
    public function testSyncTransportMiddlewareDetectsSyncTransport(): void
    {
        $envelope = $this->handle(
            new SyncTransportMiddleware(),
            new Envelope(new TriggerSingleIndexMessage('products', true, false, false, new Key('queue')), [new SentStamp(SyncTransport::class, 'elastica_bridge_populate')]),
        );

        $this->assertSame(['synchronous' => true], $envelope->last(HandlerArgumentsStamp::class)?->getAdditionalArguments());
    }

    public function testSyncTransportMiddlewareDetectsAsyncTransport(): void
    {
        $envelope = $this->handle(
            new SyncTransportMiddleware(),
            new Envelope(new TriggerSingleIndexMessage('products', true, false, false, new Key('queue')), [new SentStamp(InMemoryTransport::class, 'elastica_bridge_populate')]),
        );

        $this->assertSame(['synchronous' => false], $envelope->last(HandlerArgumentsStamp::class)?->getAdditionalArguments());
    }

    public function testSyncTransportMiddlewareTreatsReceivedMessagesAsAsync(): void
    {
        // SentStamp is not sendable, so it is gone once a worker receives the message from a queue.
        $envelope = $this->handle(
            new SyncTransportMiddleware(),
            new Envelope(new TriggerSingleIndexMessage('products', true, false, false, new Key('queue'))),
        );

        $this->assertSame(['synchronous' => false], $envelope->last(HandlerArgumentsStamp::class)?->getAdditionalArguments());
    }

    public function testSyncTransportMiddlewareIgnoresOtherMessages(): void
    {
        $envelope = $this->handle(new SyncTransportMiddleware(), new Envelope(new SwitchIndex('products'), [new SentStamp(SyncTransport::class)]));

        $this->assertNull($envelope->last(HandlerArgumentsStamp::class));
    }

    public function testRetryCountMiddlewarePassesRedeliveryCount(): void
    {
        $envelope = $this->handle(
            new RetryCountMiddleware(),
            new Envelope(new CreateDocumentMessage(1, \stdClass::class, 'product_document', 'products'), [new RedeliveryStamp(2)]),
        );

        $this->assertSame(['retryCount' => 2], $envelope->last(HandlerArgumentsStamp::class)?->getAdditionalArguments());
    }

    public function testRetryCountMiddlewareDefaultsToZero(): void
    {
        $envelope = $this->handle(new RetryCountMiddleware(), new Envelope(new CreateDocumentMessage(1, \stdClass::class, 'product_document', 'products')));

        $this->assertSame(['retryCount' => 0], $envelope->last(HandlerArgumentsStamp::class)?->getAdditionalArguments());
    }

    public function testRetryCountMiddlewareIgnoresOtherMessages(): void
    {
        $envelope = $this->handle(new RetryCountMiddleware(), new Envelope(new SwitchIndex('products'), [new RedeliveryStamp(2)]));

        $this->assertNull($envelope->last(HandlerArgumentsStamp::class));
    }

    private function handle(MiddlewareInterface $middleware, Envelope $envelope): Envelope
    {
        $captured = null;
        $next = new class($captured) implements MiddlewareInterface {
            public function __construct(private ?Envelope &$captured)
            {
            }

            public function handle(Envelope $envelope, StackInterface $stack): Envelope
            {
                $this->captured = $envelope;

                return $envelope;
            }
        };

        $middleware->handle($envelope, new StackMiddleware($next));
        $this->assertInstanceOf(Envelope::class, $captured);

        return $captured;
    }
}
