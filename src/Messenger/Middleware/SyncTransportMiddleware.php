<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Messenger\Middleware;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\HandlerArgumentsStamp;
use Symfony\Component\Messenger\Stamp\SentStamp;
use Symfony\Component\Messenger\Transport\Sync\SyncTransport;

class SyncTransportMiddleware implements MiddlewareInterface
{
    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        // Check based on interface, class, stamp or something else
        if ($envelope->getMessage() instanceof SyncTransportDetectionInterface) {
            $envelope = $envelope->with(new HandlerArgumentsStamp([
                'synchronous' => $envelope->last(SentStamp::class)?->getSenderClass() === SyncTransport::class,
            ]));
        }

        return $stack->next()->handle($envelope, $stack);
    }
}
