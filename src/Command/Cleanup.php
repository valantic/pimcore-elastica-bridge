<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Command;

use Elastic\Elasticsearch\Exception\ElasticsearchException;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Valantic\ElasticaBridgeBundle\Constant\CommandConstants;
use Valantic\ElasticaBridgeBundle\Elastica\Client\ElasticsearchClient;
use Valantic\ElasticaBridgeBundle\Repository\IndexRepository;

class Cleanup extends BaseCommand
{
    use NonBundleIndexTrait;
    private const OPTION_ALL_IN_CLUSTER = 'all';
    private const OPTION_FORCE = 'force';

    public function __construct(
        private readonly ElasticsearchClient $esClient,
        private readonly IndexRepository $indexRepository,
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
                'Delete all indices in cluster including indices not created by this bundle but e.g. by Pimcore Enterprise features'
            )
            ->addOption(
                self::OPTION_FORCE,
                'f',
                InputOption::VALUE_NONE,
                'Do not ask for confirmation and instead proceed with deleting indices and aliases'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->output->writeln(
            $this->input->getOption(self::OPTION_ALL_IN_CLUSTER) === true
                ? 'Deleting ALL indices in the cluster'
                : 'Only deleting KNOWN indices'
        );

        // Skip confirmation if force option is set
        if ($this->input->getOption(self::OPTION_FORCE) !== true) {
            /** @var QuestionHelper $helper */
            $helper = $this->getHelper('question');
            $question = new ConfirmationQuestion('Are you sure you want to proceed deleting indices and aliases? (y/N)', false);

            if ($helper->ask($input, $output, $question) === false) {
                return self::FAILURE;
            }
        }

        $indices = $this->getIndices();

        foreach ($indices as $index) {
            if (!$this->shouldProcessNonBundleIndex($index)) {
                continue;
            }

            $client = $this->esClient->getIndex($index);

            if ($client->getSettings()->getBool('hidden')) {
                continue;
            }

            foreach ($client->getAliases() as $alias) {
                $client->removeAlias($alias);
            }

            try {
                $client->delete();
            } catch (ElasticsearchException $e) {
                $this->output->writeln(sprintf('<error>%s</error>', $e->getMessage()));
            }
        }

        return self::SUCCESS;
    }

    /**
     * @return string[]
     */
    private function getIndices(): array
    {
        if ($this->input->getOption(self::OPTION_ALL_IN_CLUSTER) === true) {
            return $this->esClient->getCluster()->getIndexNames();
        }

        $indices = [];

        foreach ($this->indexRepository->flattenedAll() as $indexConfig) {
            if ($indexConfig->usesBlueGreenIndices()) {
                $indices[] = $indexConfig->getBlueGreenActiveElasticaIndex()->getName();
                $indices[] = $indexConfig->getBlueGreenInactiveElasticaIndex()->getName();

                continue;
            }

            $indices[] = $indexConfig->getName();
        }

        return $indices;
    }
}
