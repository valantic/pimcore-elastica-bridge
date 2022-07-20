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
    protected const OPTION_ONLY_KNOWN = 'only-known';

    public function __construct(
        protected ElasticsearchClient $esClient,
        protected IndexRepository $indexRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName(self::COMMAND_NAMESPACE . 'cleanup')
            ->setDescription('Deletes ALL Elasticsearch indices and aliases')
            ->addOption(
                self::OPTION_ONLY_KNOWN,
                'k',
                InputOption::VALUE_NONE,
                'Delete only indices known to (i.e. created by) the bundle'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->output->writeln(
            $this->input->getOption(self::OPTION_ONLY_KNOWN)
                ? 'Only deleting KNOWN indices'
                : 'Deleting ALL indices in the cluster'
        );
        $helper = $this->getHelper('question');
        $question = new ConfirmationQuestion('Are you sure you want to delete all indices and aliases? (y/n)', false);

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
        if ($this->input->getOption(self::OPTION_ONLY_KNOWN)) {
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

        return $this->esClient->getCluster()->getIndexNames();
    }
}
