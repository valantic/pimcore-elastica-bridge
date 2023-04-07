<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Input\InputOption;
use Valantic\ElasticaBridgeBundle\Elastica\Client\ElasticsearchClient;
use Valantic\ElasticaBridgeBundle\Repository\IndexRepository;

class Cleanup extends BaseCommand
{
    protected const OPTION_ALL_IN_CLUSTER = 'all';

    public function __construct(
        protected ElasticsearchClient $esClient,
        protected IndexRepository $indexRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName(self::COMMAND_NAMESPACE . 'cleanup')
            ->setDescription('Deletes Elasticsearch indices and aliases known to (i.e. created by) the bundle')
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
        $helper = $this->getHelper('question');
        $question = new ConfirmationQuestion('Are you sure you want to proceed deleting indices and aliases? (y/N)', false);

        if (!$helper->ask($input, $output, $question)) {
            return self::FAILURE;
        }

        $indices = $this->getIndices();

        foreach ($indices as $index) {
            $client = $this->esClient->getIndex($index);

            foreach ($client->getAliases() as $alias) {
                $client->removeAlias($alias);
            }

            $client->delete();
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

        foreach ($this->indexRepository->flattened() as $indexConfig) {
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
