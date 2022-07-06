<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Valantic\ElasticaBridgeBundle\Elastica\Client\ElasticsearchClient;

class Cleanup extends BaseCommand
{
    public function __construct(
        protected ElasticsearchClient $esClient,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName(self::COMMAND_NAMESPACE . 'cleanup')
            ->setDescription('Deletes all Elasticsearch indices and aliases');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $helper = $this->getHelper('question');
        $question = new ConfirmationQuestion('Are you sure you want to delete all indices and aliases? (y/n)', false);

        if (!$helper->ask($input, $output, $question)) {
            return self::FAILURE;
        }

        $indices = $this->esClient->getCluster()->getIndexNames();

        foreach ($indices as $index) {
            $client = $this->esClient->getIndex($index);

            foreach ($client->getAliases() as $alias) {
                $client->removeAlias($alias);
            }

            $client->delete();
        }

        return self::SUCCESS;
    }
}
