<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Command;

use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Valantic\ElasticaBridgeBundle\Constant\CommandConstants;
use Valantic\ElasticaBridgeBundle\Service\CleanupService;

class Cleanup extends BaseCommand
{
    private const string OPTION_ALL_IN_CLUSTER = 'all';

    private const string OPTION_FORCE = 'force';

    private const string OPTION_DRY_RUN = 'dry-run';

    public function __construct(
        private readonly CleanupService $cleanupService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName(CommandConstants::COMMAND_CLEANUP)
            ->setDescription('Deletes Elasticsearch indices and aliases known to (i.e. created by) the bundle')
            ->addOption(
                self::OPTION_ALL_IN_CLUSTER,
                'a',
                InputOption::VALUE_NONE,
                'Delete all indices in cluster including indices not created by this bundle but e.g. by Pimcore Enterprise features',
            )
            ->addOption(
                self::OPTION_FORCE,
                'f',
                InputOption::VALUE_NONE,
                'Do not ask for confirmation and instead proceed with deleting indices and aliases',
            )
            ->addOption(
                self::OPTION_DRY_RUN,
                'd',
                InputOption::VALUE_NONE,
                'Only list the indices and aliases that would be deleted',
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $allInCluster = $this->input->getOption(self::OPTION_ALL_IN_CLUSTER) === true;
        $dryRun = $this->input->getOption(self::OPTION_DRY_RUN) === true;

        $this->output->writeln(
            $allInCluster
                ? 'Deleting ALL indices in the cluster'
                : 'Only deleting KNOWN indices',
        );

        if ($dryRun) {
            $this->output->writeln('<info>Dry run: nothing will be deleted</info>');
        }

        // Skip confirmation if force option is set or nothing will be deleted
        if (!$dryRun && $this->input->getOption(self::OPTION_FORCE) !== true) {
            /** @var QuestionHelper $helper */
            $helper = $this->getHelper('question');
            $question = new ConfirmationQuestion('Are you sure you want to proceed deleting indices and aliases? (y/N)', false);

            if ($helper->ask($input, $output, $question) === false) {
                return self::FAILURE;
            }
        }

        $result = $this->cleanupService->cleanUp($allInCluster, $dryRun);

        foreach ($result->getRemovedAliases() as $indexName => $aliases) {
            foreach ($aliases as $alias) {
                $this->output->writeln(sprintf(
                    $dryRun ? 'Would remove alias %s from index %s' : 'Removed alias %s from index %s',
                    $alias,
                    $indexName,
                ));
            }
        }

        foreach ($result->getDeletedIndices() as $indexName) {
            $this->output->writeln(sprintf($dryRun ? 'Would delete index %s' : 'Deleted index %s', $indexName));
        }

        foreach ($result->getErrors() as $message) {
            $this->output->writeln(sprintf('<error>%s</error>', $message));
        }

        return self::SUCCESS;
    }
}
