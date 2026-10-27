<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Tests\Unit\Service;

use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use Valantic\ElasticaBridgeBundle\Document\DocumentInterface;
use Valantic\ElasticaBridgeBundle\Document\TenantAwareInterface as DocumentTenantAwareInterface;
use Valantic\ElasticaBridgeBundle\Enum\DocumentType;
use Valantic\ElasticaBridgeBundle\Index\DocumentContext;
use Valantic\ElasticaBridgeBundle\Index\IndexContext;
use Valantic\ElasticaBridgeBundle\Index\IndexInterface;
use Valantic\ElasticaBridgeBundle\Index\TenantAwareInterface as IndexTenantAwareInterface;
use Valantic\ElasticaBridgeBundle\Service\DocumentHelper;
use Valantic\ElasticaBridgeBundle\Tests\Helpers\PimcoreElementFactory;

class DocumentHelperTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private DocumentHelper $helper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->helper = new DocumentHelper();
    }

    public function testElementToDocumentCreatesElasticaDocument(): void
    {
        $element = PimcoreElementFactory::createDataObject(123);

        $document = \Mockery::mock(DocumentInterface::class);
        $document->shouldReceive('getType')->andReturn(DocumentType::DATA_OBJECT);
        $document->shouldReceive('getSubType')->andReturn('TestClass');
        $document->shouldReceive('getNormalized')->with($element)->andReturn(['field' => 'value']);
        $document->shouldReceive('getElasticsearchId')->with($element)->andReturn('object123');

        $result = $this->helper->elementToDocument($document, $element);

        $this->assertSame('object123', $result->getId());
        $this->assertArrayHasKey('field', $result->getData());
        $this->assertSame('value', $result->getData()['field']);
        $this->assertArrayHasKey(DocumentInterface::META_TYPE, $result->getData());
        $this->assertArrayHasKey(DocumentInterface::META_SUB_TYPE, $result->getData());
        $this->assertArrayHasKey(DocumentInterface::META_ID, $result->getData());
        $this->assertSame(123, $result->getData()[DocumentInterface::META_ID]);
    }

    public function testElementToDocumentsForContextsWithoutContextsReturnsSingleDocument(): void
    {
        $element = PimcoreElementFactory::createDataObject(123);

        $document = \Mockery::mock(DocumentInterface::class);
        $document->shouldReceive('shouldIndex')->with($element)->andReturn(true);
        $document->shouldReceive('getType')->andReturn(DocumentType::DATA_OBJECT);
        $document->shouldReceive('getSubType')->andReturn('TestClass');
        $document->shouldReceive('getNormalized')->with($element)->andReturn(['field' => 'value']);
        $document->shouldReceive('getElasticsearchId')->with($element)->andReturn('object123');

        $index = \Mockery::mock(IndexInterface::class);
        $index->shouldReceive('getContexts')->andReturn([]);

        $result = $this->helper->elementToDocumentsForContexts($document, $element, $index);

        $this->assertCount(1, $result);
        $this->assertSame('object123', $result[0]->getId());
        $this->assertArrayNotHasKey(DocumentInterface::META_LANGUAGE, $result[0]->getData());
    }

    public function testElementToDocumentsForContextsWithoutContextsRespectsShouldIndex(): void
    {
        $element = PimcoreElementFactory::createDataObject(123, published: false);

        $document = \Mockery::mock(DocumentInterface::class);
        $document->shouldReceive('shouldIndex')->with($element)->andReturn(false);
        $document->shouldNotReceive('getNormalized');

        $index = \Mockery::mock(IndexInterface::class);
        $index->shouldReceive('getContexts')->andReturn([]);

        $this->assertSame([], $this->helper->elementToDocumentsForContexts($document, $element, $index));
    }

    public function testElementToDocumentsForContextsCreatesDocumentPerDocumentContext(): void
    {
        $element = PimcoreElementFactory::createDataObject(123);
        $german = new IndexContext(language: 'de');
        $french = new IndexContext(language: 'fr');

        $document = \Mockery::mock(DocumentInterface::class);
        $document->shouldNotReceive('shouldIndex');
        $document->shouldReceive('getType')->andReturn(DocumentType::DATA_OBJECT);
        $document->shouldReceive('getSubType')->andReturn('TestClass');
        $document->shouldReceive('getDocumentContexts')->with($element, $german)->andReturn([
            new DocumentContext(language: 'de', country: 'CH'),
            new DocumentContext(language: 'de', country: 'DE'),
        ]);
        $document->shouldReceive('getDocumentContexts')->with($element, $french)->andReturn([]);
        $document->shouldReceive('getIdForContext')->andReturnUsing(
            static fn ($element, DocumentContext $context): string => 'object123_' . $context->country,
        );
        $document->shouldReceive('getNormalizedForContext')->andReturnUsing(
            static fn ($element, IndexContext $indexContext, DocumentContext $context): array => ['country' => $context->country],
        );

        $index = \Mockery::mock(IndexInterface::class);
        $index->shouldReceive('getContexts')->andReturn([$german, $french]);

        $result = $this->helper->elementToDocumentsForContexts($document, $element, $index);

        $this->assertCount(2, $result);
        $this->assertSame('object123_CH', $result[0]->getId());
        $this->assertSame('object123_DE', $result[1]->getId());

        $data = $result[0]->getData();
        $this->assertSame('CH', $data['country']);
        $this->assertSame(123, $data[DocumentInterface::META_ID]);
        $this->assertSame('de', $data[DocumentInterface::META_LANGUAGE]);
        $this->assertSame('CH', $data[DocumentInterface::META_COUNTRY]);
        $this->assertArrayNotHasKey(DocumentInterface::META_TENANT, $data);
    }

    public function testSetTenantIfNeededSetsTenantWhenBothAreTenantAware(): void
    {
        $document = \Mockery::mock(DocumentInterface::class, DocumentTenantAwareInterface::class);
        $document->shouldReceive('setTenant')->with('tenant1')->once();

        $index = \Mockery::mock(IndexInterface::class, IndexTenantAwareInterface::class);
        $index->shouldReceive('getTenant')->andReturn('tenant1');

        $this->helper->setTenantIfNeeded($document, $index);
    }

    public function testSetTenantIfNeededDoesNothingWhenIndexNotTenantAware(): void
    {
        $document = \Mockery::mock(DocumentInterface::class, DocumentTenantAwareInterface::class);
        $document->shouldNotReceive('setTenant');

        $index = \Mockery::mock(IndexInterface::class);

        $this->helper->setTenantIfNeeded($document, $index);
    }

    public function testSetTenantIfNeededDoesNothingWhenDocumentNotTenantAware(): void
    {
        $document = \Mockery::mock(DocumentInterface::class);

        $index = \Mockery::mock(IndexInterface::class, IndexTenantAwareInterface::class);
        $index->shouldReceive('getTenant')->andReturn('tenant1');

        $this->helper->setTenantIfNeeded($document, $index);

        // Should not throw an error
        $this->assertTrue(true);
    }

    public function testResetTenantIfNeededResetsTenantWhenBothAreTenantAware(): void
    {
        $document = \Mockery::mock(DocumentInterface::class, DocumentTenantAwareInterface::class);
        $document->shouldReceive('resetTenant')->once();

        $index = \Mockery::mock(IndexInterface::class, IndexTenantAwareInterface::class);

        $this->helper->resetTenantIfNeeded($document, $index);
    }

    public function testResetTenantIfNeededDoesNothingWhenIndexNotTenantAware(): void
    {
        $document = \Mockery::mock(DocumentInterface::class, DocumentTenantAwareInterface::class);
        $document->shouldNotReceive('resetTenant');

        $index = \Mockery::mock(IndexInterface::class);

        $this->helper->resetTenantIfNeeded($document, $index);
    }

    public function testResetTenantIfNeededDoesNothingWhenDocumentNotTenantAware(): void
    {
        $document = \Mockery::mock(DocumentInterface::class);

        $index = \Mockery::mock(IndexInterface::class, IndexTenantAwareInterface::class);

        $this->helper->resetTenantIfNeeded($document, $index);

        // Should not throw an error
        $this->assertTrue(true);
    }
}
