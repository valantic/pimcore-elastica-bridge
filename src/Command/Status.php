<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Command;

use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Valantic\ElasticaBridgeBundle\Elastica\Client\ElasticsearchClient;
use Valantic\ElasticaBridgeBundle\Exception\Index\BlueGreenIndicesIncorrectlySetupException;
use Valantic\ElasticaBridgeBundle\Repository\IndexRepository;

class Status extends BaseCommand
{
    protected ElasticsearchClient $esClient;
    protected IndexRepository $indexRepository;

    public function __construct(
        IndexRepository $indexRepository,
        ElasticsearchClient $esClient
    ) {
        parent::__construct();
        $this->esClient = $esClient;
        $this->indexRepository = $indexRepository;
    }

    protected function configure(): void
    {
        $this->setName(self::COMMAND_NAMESPACE . 'status')
            ->setDescription('Displays the status of the configured Elasticsearch indices');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $table = new Table($output);
        $table
            ->setHeaders(['Host', 'Port', 'Version'])
            ->setRows([[
                $this->esClient->getConfig('host'),
                $this->esClient->getConfig('port'),
                $this->esClient->getVersion(),
            ]])
            ->setHeaderTitle('Cluster');
        $table->render();

        $this->output->writeln('');

        $data = [];
        foreach ($this->indexRepository->all() as $indexConfig) {
            $name = $indexConfig->getName();
            $exists = $indexConfig->getElasticaIndex()->exists();
            $useBlueGreen = $indexConfig->usesBlueGreenIndices();
            $hasBlueGreen = $indexConfig->hasBlueGreenIndices();
            $numDocs = 'N/A';
            $size = 'N/A';
            $activeBlueGreen = 'N/A';

            if ($exists) {
                $stats = $indexConfig->getElasticaIndex()->getStats()->get()['indices'];
                $stats = array_values($stats)[0]['total'];
                $numDocs = $stats['docs']['count'];
                $size = $stats['store']['size_in_bytes'];
            }

            if ($hasBlueGreen) {
                try {
                    $activeBlueGreen = $indexConfig->getBlueGreenActiveElasticaIndex()->getName();
                } catch (BlueGreenIndicesIncorrectlySetupException $exception) {
                    $hasBlueGreen = false;
                }
            }

            $data[] = [
                $name,
                $this->formatBoolean($exists),
                $numDocs,
                is_int($size) ? $this->formatBytes($size) : $size,
                sprintf(
                    '%s / %s / %s',
                    $this->formatBoolean($useBlueGreen),
                    $this->formatBoolean($hasBlueGreen),
                    $activeBlueGreen
                ),
            ];
        }

        $table = new Table($output);
        $table
            ->setHeaders(['Name', 'Exists', '# Docs', 'Size', 'Blue/Green: use / present / active'])
            ->setRows($data)
            ->setHeaderTitle('Indices');
        $table->render();

        return 0;
    }

    protected function formatBoolean(bool $val): string
    {
        if ($val) {
            return '✓';
        }

        return '✗';
    }

    /**
     * @param int $bytes
     *
     * @return string
     *
     * @see https://stackoverflow.com/a/2510540
     */
    protected function formatBytes(int $bytes): string
    {
        $base = log($bytes, 1024);
        $suffixes = ['', 'K', 'M', 'G', 'T'];

        return round(1024 ** ($base - floor($base)), 2) . ' ' . $suffixes[floor($base)] . 'B';
    }
}
