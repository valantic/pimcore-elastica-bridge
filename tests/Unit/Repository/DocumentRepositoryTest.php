<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Tests\Unit\Repository;

use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use Valantic\ElasticaBridgeBundle\Document\DocumentInterface;
use Valantic\ElasticaBridgeBundle\Repository\DocumentRepository;
use Valantic\ElasticaBridgeBundle\Tests\Helpers\DocumentFactory;

class DocumentRepositoryTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    public function testCanGetDocumentByClass(): void
    {
        $document = DocumentFactory::createTestDocument();

        $repository = new DocumentRepository(new \ArrayIterator([$document]));

        $result = $repository->get($document::class);

        $this->assertInstanceOf(DocumentInterface::class, $result);
        $this->assertSame($document, $result);
    }

    public function testInheritsAbstractRepositoryFunctionality(): void
    {
        $document1 = DocumentFactory::createTestDocument();
        $document2 = DocumentFactory::createTestDocument();

        $repository = new DocumentRepository(new \ArrayIterator([$document1, $document2]));

        // Should be able to retrieve documents
        $this->assertInstanceOf(DocumentInterface::class, $repository->get($document1::class));
        $this->assertInstanceOf(DocumentInterface::class, $repository->get($document2::class));
    }
}
