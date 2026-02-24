<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Tests\Unit\Index;

use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use Valantic\ElasticaBridgeBundle\Document\DocumentInterface;
use Valantic\ElasticaBridgeBundle\Elastica\Client\ElasticsearchClient;
use Valantic\ElasticaBridgeBundle\Enum\DocumentType;
use Valantic\ElasticaBridgeBundle\Index\AbstractIndex;
use Valantic\ElasticaBridgeBundle\Repository\DocumentRepository;
use Valantic\ElasticaBridgeBundle\Tests\Helpers\DocumentFactory;
use Valantic\ElasticaBridgeBundle\Tests\Helpers\PimcoreElementFactory;

class AbstractIndexTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private ElasticsearchClient $client;
    private DocumentRepository $documentRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = \Mockery::mock(ElasticsearchClient::class);
        $this->documentRepository = \Mockery::mock(DocumentRepository::class);
    }

    public function testGetMappingReturnsMetaFields(): void
    {
        $index = $this->createTestIndex();

        $mapping = $index->getMapping();

        $this->assertArrayHasKey('properties', $mapping);
        $this->assertArrayHasKey(DocumentInterface::META_ID, $mapping['properties']);
        $this->assertArrayHasKey(DocumentInterface::META_TYPE, $mapping['properties']);
        $this->assertArrayHasKey(DocumentInterface::META_SUB_TYPE, $mapping['properties']);
    }

    public function testGetSettingsReturnsEmptyArrayByDefault(): void
    {
        $index = $this->createTestIndex();

        $this->assertSame([], $index->getSettings());
    }

    public function testHasMappingReturnsTrueWhenMappingExists(): void
    {
        $index = $this->createTestIndex();

        $this->assertTrue($index->hasMapping());
    }

    public function testGetBatchSizeReturnsDefault5000(): void
    {
        $index = $this->createTestIndex();

        $this->assertSame(5000, $index->getBatchSize());
    }

    public function testShouldPopulateInSubprocessesReturnsFalseByDefault(): void
    {
        $index = $this->createTestIndex();

        $this->assertFalse($index->shouldPopulateInSubprocesses());
    }

    public function testGetCreateArgumentsIncludesMappingsAndSettings(): void
    {
        $index = $this->createTestIndex();

        $args = $index->getCreateArguments();

        $this->assertArrayHasKey('mappings', $args);
        $this->assertIsArray($args['mappings']);
    }

    public function testIsElementAllowedInIndexReturnsTrueForMatchingElement(): void
    {
        $element = PimcoreElementFactory::createDataObject(1);
        $document = DocumentFactory::createTestDocument(DocumentType::DATA_OBJECT, $element::class);

        $this->documentRepository
            ->shouldReceive('get')
            ->once()
            ->andReturn($document)
        ;

        $index = $this->createTestIndex([$document::class]);

        $this->assertTrue($index->isElementAllowedInIndex($element));
    }

    public function testIsElementAllowedInIndexReturnsFalseForNonMatchingElement(): void
    {
        $element = PimcoreElementFactory::createAsset(1);
        $document = DocumentFactory::createTestDocument(DocumentType::DATA_OBJECT);

        $this->documentRepository
            ->shouldReceive('get')
            ->once()
            ->andReturn($document)
        ;

        $index = $this->createTestIndex([$document::class]);

        $this->assertFalse($index->isElementAllowedInIndex($element));
    }

    public function testFindDocumentInstanceByPimcoreReturnsMatchingDocument(): void
    {
        $element = PimcoreElementFactory::createDataObject(1);
        $document = DocumentFactory::createTestDocument(DocumentType::DATA_OBJECT, $element::class);

        $this->documentRepository
            ->shouldReceive('get')
            ->once()
            ->andReturn($document)
        ;

        $index = $this->createTestIndex([$document::class]);

        $result = $index->findDocumentInstanceByPimcore($element);

        $this->assertInstanceOf(DocumentInterface::class, $result);
    }

    public function testFindDocumentInstanceByPimcoreReturnsNullForNonMatchingElement(): void
    {
        $element = PimcoreElementFactory::createAsset(1);
        $document = DocumentFactory::createTestDocument(DocumentType::DATA_OBJECT);

        $this->documentRepository
            ->shouldReceive('get')
            ->once()
            ->andReturn($document)
        ;

        $index = $this->createTestIndex([$document::class]);

        $result = $index->findDocumentInstanceByPimcore($element);

        $this->assertNull($result);
    }

    private function createTestIndex(array $allowedDocuments = []): AbstractIndex
    {
        return new class($this->client, $this->documentRepository, $allowedDocuments) extends AbstractIndex {
            public function __construct(
                ElasticsearchClient $client,
                DocumentRepository $documentRepository,
                private readonly array $allowedDocuments = [],
            ) {
                parent::__construct($client, $documentRepository);
            }

            public function getName(): string
            {
                return 'test_index';
            }

            public function getAllowedDocuments(): array
            {
                return $this->allowedDocuments;
            }
        };
    }
}
