<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Tests\Unit\Messenger\Handler;

use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use Pimcore\Model\DataObject\AbstractObject;
use Pimcore\Model\DataObject\Concrete;
use Valantic\ElasticaBridgeBundle\Messenger\Handler\RefreshElementHandler;
use Valantic\ElasticaBridgeBundle\Messenger\Message\RefreshElement;
use Valantic\ElasticaBridgeBundle\Service\PropagateChanges;
use Valantic\ElasticaBridgeBundle\Tests\Helpers\DeletedDataObjectStub;

class RefreshElementHandlerTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    public function testRestoresDeletedElementAsSkeletonWithParentIdAndType(): void
    {
        $deleted = new DeletedDataObjectStub();
        $deleted->setId(7);
        $deleted->setParentId(3);
        $deleted->setType(AbstractObject::OBJECT_TYPE_VARIANT);

        $propagateChanges = \Mockery::mock(PropagateChanges::class);
        $propagateChanges
            ->shouldReceive('handle')
            ->once()
            ->with(\Mockery::on(static fn (Concrete $element): bool => $element instanceof DeletedDataObjectStub
                && $element->getId() === 7
                && $element->getParentId() === 3
                && $element->getType() === AbstractObject::OBJECT_TYPE_VARIANT
                && !$element->isPublished()))
        ;

        (new RefreshElementHandler($propagateChanges))(new RefreshElement($deleted));
    }

    public function testRestoresDeletedElementFromMessageWithoutParentIdAndType(): void
    {
        // Simulates a message that was queued before parent ID and type were carried along
        $message = (new \ReflectionClass(RefreshElement::class))->newInstanceWithoutConstructor();
        $message->className = DeletedDataObjectStub::class;
        $message->id = 7;

        $propagateChanges = \Mockery::mock(PropagateChanges::class);
        $propagateChanges
            ->shouldReceive('handle')
            ->once()
            ->with(\Mockery::on(static fn (Concrete $element): bool => $element->getId() === 7
                && $element->getParentId() === null
                && $element->getType() === AbstractObject::OBJECT_TYPE_OBJECT))
        ;

        (new RefreshElementHandler($propagateChanges))($message);
    }
}
