<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Tests\Unit\Messenger\Message;

use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use Valantic\ElasticaBridgeBundle\Messenger\Message\RefreshElement;
use Valantic\ElasticaBridgeBundle\Tests\Helpers\PimcoreElementFactory;

class RefreshElementTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    public function testCarriesParentIdAndTypeOfTheElement(): void
    {
        $message = new RefreshElement(PimcoreElementFactory::createVariant(5, parentId: 99));

        $this->assertSame(5, $message->id);
        $this->assertSame(99, $message->parentId);
        $this->assertSame('variant', $message->type);
    }

    public function testCarriesMissingParentId(): void
    {
        $message = new RefreshElement(PimcoreElementFactory::createDataObject(5));

        $this->assertNull($message->parentId);
        $this->assertSame('object', $message->type);
    }

    public function testMessageQueuedBeforeParentIdAndTypeExistedStillHasNullDefaults(): void
    {
        // Simulates unserializing a message that was queued before the properties were added
        $message = (new \ReflectionClass(RefreshElement::class))->newInstanceWithoutConstructor();

        $this->assertNull($message->parentId);
        $this->assertNull($message->type);
    }
}
