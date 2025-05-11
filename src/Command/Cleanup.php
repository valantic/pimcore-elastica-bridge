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
    use NonBundleIndexTrait;
    private const OPTION_ALL_IN_CLUSTER = 'all';
    private const OPTION_DRY_RUN = 'dry-run';

    public function __construct(
        private readonly CleanupService $cleanupService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName(CommandConstants::COMMAND_CLEANUP)
            ->setDescription('Deletes Elasticsearch indices and aliases known to (i.e. created by) the bundle')
            ->addOption(self::OPTION_DRY_RUN, 'd', InputOption::VALUE_NONE, 'Only simulate the cleanup')
            ->addOption(
                self::OPTION_ALL_IN_CLUSTER,
                'a',
                InputOption::VALUE_NONE,
                'Delete all indices in cluster including indices not created by this bundle but e.g. by Pimcore Enterprise features'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->output->writeln(
            $this->input->getOption(self::OPTION_ALL_IN_CLUSTER) === true
                ? 'Deleting ALL indices in the cluster'
                : 'Only deleting KNOWN indices'
        );
        $verb = $this->input->getOption(self::OPTION_DRY_RUN) === true
            ? 'simulate'
            : 'proceed';
        /** @var QuestionHelper $helper */
        $helper = $this->getHelper('question');
        $question = new ConfirmationQuestion(sprintf('Are you sure you want to %s deleting indices and aliases? (y/N)', $verb), false);

        if ($helper->ask($input, $output, $question) === false) {
            return self::FAILURE;
        }

        $messages = $this->cleanupService->cleanUp(
            $this->input->getOption(self::OPTION_ALL_IN_CLUSTER) === true,
            $this->input->getOption(self::OPTION_DRY_RUN) === true
        );

        foreach ($messages as $message) {
            $this->output->writeln($message);
        }

        return self::SUCCESS;
    }
}
