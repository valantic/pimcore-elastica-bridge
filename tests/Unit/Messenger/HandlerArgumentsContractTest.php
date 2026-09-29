<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Tests\Unit\Messenger;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Valantic\ElasticaBridgeBundle\Messenger\Handler\CreateDocumentHandler;
use Valantic\ElasticaBridgeBundle\Messenger\Handler\PopulateHandler;
use Valantic\ElasticaBridgeBundle\Messenger\Middleware\SyncTransportDetectionInterface;

/**
 * HandlerArgumentsStamp passes arguments to handlers by name, so the argument constants must match the handler parameters.
 */
class HandlerArgumentsContractTest extends TestCase
{
    /**
     * @return iterable<string, array{class-string, string, string}>
     */
    public static function handlerArguments(): iterable
    {
        yield 'PopulateHandler synchronous' => [PopulateHandler::class, SyncTransportDetectionInterface::SYNCHRONOUS_ARGUMENT, 'bool'];

        yield 'CreateDocumentHandler synchronous' => [CreateDocumentHandler::class, SyncTransportDetectionInterface::SYNCHRONOUS_ARGUMENT, 'bool'];
    }

    /**
     * @param class-string $handler
     */
    #[DataProvider('handlerArguments')]
    public function testHandlerAcceptsArgument(string $handler, string $argument, string $type): void
    {
        $parameters = [];

        foreach ((new \ReflectionMethod($handler, '__invoke'))->getParameters() as $parameter) {
            $parameters[$parameter->getName()] = $parameter;
        }

        $this->assertArrayHasKey($argument, $parameters, sprintf('%s::__invoke() has no parameter $%s', $handler, $argument));
        $this->assertSame($type, (string) $parameters[$argument]->getType());
    }
}
