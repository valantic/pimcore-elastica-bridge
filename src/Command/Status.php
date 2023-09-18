<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Command;

use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Valantic\ElasticaBridgeBundle\Elastica\Client\ElasticsearchClient;
use Valantic\ElasticaBridgeBundle\Exception\Index\BlueGreenIndicesIncorrectlySetupException;
use Valantic\ElasticaBridgeBundle\Index\IndexInterface;
use Valantic\ElasticaBridgeBundle\Repository\IndexRepository;

class Status extends BaseCommand
{
    /**
     * @var array<int,array<int,mixed>>
     */
    private array $bundleIndices = [];
    /**
     * @var array<int,array<int,mixed>>
     */
    private array $otherIndices = [];
    /**
     * @var string[]
     */
    private array $skipOtherIndices = [];

    public function __construct(
        private readonly IndexRepository $indexRepository,
        private readonly ElasticsearchClient $esClient,
    ) {
        parent::__construct();
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

        foreach ($this->indexRepository->flattened() as $indexConfig) {
            $this->processBundleIndex($indexConfig);
        }

        $table = new Table($output);
        $table
            ->setHeaders(['Name', 'Exists', '# Docs', 'Size', 'Blue/Green: use / present / active'])
            ->setRows($this->bundleIndices)
            ->setHeaderTitle('Indices (managed by this bundle)');
        $table->render();

        foreach ($this->esClient->getCluster()->getIndexNames() as $indexName) {
            if (in_array($indexName, $this->skipOtherIndices, true)) {
                continue;
            }
            $this->processOtherIndex($indexName);
        }

        $this->output->writeln('');

        $table = new Table($output);
        $table
            ->setHeaders(['Name', '# Docs', 'Size'])
            ->setRows($this->otherIndices)
            ->setHeaderTitle('Other indices in this cluster');
        $table->render();

        return self::SUCCESS;
    }

    private function formatBoolean(bool $val): string
    {
        return $val ? '✓' : '✗';
    }

    /**
     * @see https://stackoverflow.com/a/2510540
     */
    private function formatBytes(int $bytes): string
    {
        $base = log($bytes, 1024);
        $suffixes = ['', 'K', 'M', 'G', 'T'];

        return round(1024 ** ($base - floor($base)), 2) . ' ' . $suffixes[floor($base)] . 'B';
    }

    private function processBundleIndex(IndexInterface $indexConfig): void
    {
        $name = $indexConfig->getName();
        $exists = $indexConfig->getElasticaIndex()->exists();
        $useBlueGreen = $indexConfig->usesBlueGreenIndices();
        $hasBlueGreen = $indexConfig->hasBlueGreenIndices();
        $numDocs = 'N/A';
        $size = 'N/A';
        $activeBlueGreen = 'N/A';

        if ($exists) {
            $stats = $indexConfig->getElasticaIndex()->getStats()->get('indices');
            $stats = array_values($stats)[0]['primaries'];
            $numDocs = $stats['docs']['count'];
            $size = $stats['store']['size_in_bytes'];
        }

        if ($hasBlueGreen) {
            try {
                $activeBlueGreen = $indexConfig->getBlueGreenActiveElasticaIndex()->getName();
                $this->skipOtherIndices[] = $indexConfig->getBlueGreenActiveElasticaIndex()->getName();
                $this->skipOtherIndices[] = $indexConfig->getBlueGreenInactiveElasticaIndex()->getName();
            } catch (BlueGreenIndicesIncorrectlySetupException) {
                $hasBlueGreen = false;
            }
        }

        $this->skipOtherIndices[] = $indexConfig->getName();

        $this->bundleIndices[] = [
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

    private function processOtherIndex(string $indexName): void
    {
        $index = $this->esClient->getIndex($indexName);

        $stats = $index->getStats()->get()['indices'];
        $stats = array_values($stats)[0]['primaries'];
        $numDocs = $stats['docs']['count'];
        $size = $stats['store']['size_in_bytes'];

        $this->otherIndices[] = [
            $indexName,
            $numDocs,
            is_int($size) ? $this->formatBytes($size) : $size,
        ];
    }
}
