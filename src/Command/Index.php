<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Valantic\ElasticaBridgeBundle\Constant\CommandConstants;
use Valantic\ElasticaBridgeBundle\Messenger\Message\PopulateIndexMessage;
use Valantic\ElasticaBridgeBundle\Repository\IndexRepository;
use Valantic\ElasticaBridgeBundle\Service\LockService;
use Valantic\ElasticaBridgeBundle\Service\PopulateIndexService;

class Index extends BaseCommand
{
    private const ARGUMENT_INDEX = 'index';
    private const OPTION_DELETE = 'delete';
    private const OPTION_POPULATE = 'populate';
    private const OPTION_LOCK_RELEASE = 'ignore-locks';
    private const OPTION_NO_COOLDOWN = 'no-cooldown';

    public function __construct(
        private readonly IndexRepository $indexRepository,
        private readonly MessageBusInterface $messengerBusElasticaBridge,
        private readonly PopulateIndexService $populateIndexService,
        private readonly LockService $lockService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName(CommandConstants::COMMAND_INDEX)
            ->setDescription('Ensures all the indices are present and populated.')
            ->addArgument(
                self::ARGUMENT_INDEX,
                InputArgument::IS_ARRAY | InputArgument::OPTIONAL,
                'Optional: indices to process. Defaults to all if empty'
            )
            ->addOption(
                self::OPTION_DELETE,
                'd',
                InputOption::VALUE_NONE,
                'Delete i.e. re-create existing indices'
            )
            ->addOption(
                self::OPTION_POPULATE,
                'p',
                InputOption::VALUE_NONE,
                'Populate indices'
            )
            ->addOption(
                self::OPTION_LOCK_RELEASE,
                'l',
                InputOption::VALUE_NONE,
                'Force all indexing locks to be released'
            )
            ->addOption(
                self::OPTION_NO_COOLDOWN,
                null,
                InputOption::VALUE_NONE,
                'disable cooldown',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $skippedIndices = [];
        $this->populateIndexService->setVerbosity($this->output->getVerbosity());
        $this->populateIndexService->setShouldDelete($this->input->getOption(self::OPTION_DELETE) === true);
        $populate = $this->input->getOption(self::OPTION_POPULATE) === true;
        $lockRelease = $this->input->getOption(self::OPTION_LOCK_RELEASE) === true;
        $noCooldown = $this->input->getOption(self::OPTION_NO_COOLDOWN) === true;

        foreach ($this->indexRepository->flattenedAll() as $indexConfig) {
            if (
                is_array($this->input->getArgument(self::ARGUMENT_INDEX))
                && count($this->input->getArgument(self::ARGUMENT_INDEX)) > 0
                && !in_array($indexConfig->getName(), $this->input->getArgument(self::ARGUMENT_INDEX), true)
            ) {
                $skippedIndices[] = $indexConfig->getName();

                continue;
            }

            $this->lockService->unlockExecution($indexConfig->getName());

            foreach ($this->populateIndexService->triggerSingleIndex($indexConfig, $populate, $lockRelease, $noCooldown) as $message) {
                if ($message instanceof PopulateIndexMessage) {
                    $this->messengerBusElasticaBridge->dispatch($message->message);
                }
            }
        }

        if (count($skippedIndices) > 0) {
            $this->output->writeln('');
            $this->output->writeln(
                sprintf('<info>Skipped the following indices: %s</info>', implode(', ', $skippedIndices))
            );
        }

        return self::SUCCESS;
    }
}
