<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Tests\Unit\Messenger\Scheduler;

use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Scheduler\Generator\MessageContext;
use Valantic\ElasticaBridgeBundle\Messenger\Message\PopulateIndexMessage;
use Valantic\ElasticaBridgeBundle\Messenger\Message\SwitchIndex;
use Valantic\ElasticaBridgeBundle\Messenger\Scheduler\PopulateIndexProvider;
use Valantic\ElasticaBridgeBundle\Repository\ConfigurationRepository;
use Valantic\ElasticaBridgeBundle\Service\PopulateIndexService;

class PopulateIndexProviderTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    public function testSchedulesPopulationAtConfiguredInterval(): void
    {
        $envelope = new Envelope(new PopulateIndexMessage(new SwitchIndex('products')));
        $populateIndexService = \Mockery::mock(PopulateIndexService::class);
        $populateIndexService->shouldReceive('processScheduler')->once()->andReturnUsing(static function () use ($envelope): \Generator {
            yield $envelope;
        });
        $configurationRepository = \Mockery::mock(ConfigurationRepository::class);
        $configurationRepository->shouldReceive('getInterval')->once()->andReturn(900);

        $provider = new PopulateIndexProvider($populateIndexService, $configurationRepository);
        $schedule = $provider->getSchedule();
        $recurringMessages = $schedule->getRecurringMessages();

        $this->assertSame($schedule, $provider->getSchedule(), 'schedule should be built once');
        $this->assertSame('populate_index_provider', $provider->getId());
        $this->assertCount(1, $recurringMessages);

        $trigger = $recurringMessages[0]->getTrigger();
        $start = new \DateTimeImmutable('2026-01-01 00:00:00');
        $trigger->getNextRunDate($start);
        $this->assertEquals($start->modify('+900 seconds'), $trigger->getNextRunDate($start->modify('+1 second')));

        $messages = iterator_to_array($recurringMessages[0]->getMessages(new MessageContext('populate_index', $recurringMessages[0]->getId(), $trigger, $start)), false);
        $this->assertSame([$envelope], $messages);
    }
}
