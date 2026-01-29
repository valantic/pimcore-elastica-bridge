<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Tests\Integration\Command;

use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use Valantic\ElasticaBridgeBundle\Command\Status;
use Valantic\ElasticaBridgeBundle\Elastica\Client\ElasticsearchClient;
use Valantic\ElasticaBridgeBundle\Repository\IndexRepository;

class StatusCommandTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private ElasticsearchClient $esClient;
    private IndexRepository $indexRepository;
    private Status $command;

    protected function setUp(): void
    {
        parent::setUp();

        $this->esClient = \Mockery::mock(ElasticsearchClient::class);
        $this->indexRepository = \Mockery::mock(IndexRepository::class);
        $this->command = new Status($this->indexRepository, $this->esClient);
    }

    public function testCommandHasCorrectName(): void
    {
        $this->assertSame('valantic:elastica-bridge:status', $this->command->getName());
    }

    public function testCommandHasDescription(): void
    {
        $this->assertNotEmpty($this->command->getDescription());
    }

    public function testCommandCanBeInstantiated(): void
    {
        $this->assertInstanceOf(Status::class, $this->command);
    }
}
