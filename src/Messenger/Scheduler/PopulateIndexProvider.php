<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Messenger\Scheduler;

use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Component\Scheduler\Trigger\CallbackMessageProvider;
use Symfony\Component\Scheduler\Trigger\PeriodicalTrigger;
use Valantic\ElasticaBridgeBundle\Repository\ConfigurationRepository;
use Valantic\ElasticaBridgeBundle\Service\PopulateIndexService;

#[AsSchedule('populate_index')]
class PopulateIndexProvider implements ScheduleProviderInterface
{
    private Schedule $schedule;

    public function __construct(
        private readonly PopulateIndexService $populateIndexService,
        private readonly ConfigurationRepository $configurationRepository,
    ) {}

    public function getSchedule(): Schedule
    {
        return $this->schedule ??= (new Schedule())
            ->with(
                RecurringMessage::trigger(
                    new PeriodicalTrigger($this->configurationRepository->getInterval()),
                    new CallbackMessageProvider($this->populateIndexService->processScheduler(...))
                ),
            );
    }

    public function getId(): string
    {
        return 'populate_index_provider';
    }
}
