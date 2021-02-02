<?php

namespace Valantic\ElasticaBridgeBundle\Command;

use Elastica\Index as ElasticaIndex;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Valantic\ElasticaBridgeBundle\Elastica\Client\ElasticsearchClient;
use Valantic\ElasticaBridgeBundle\Exception\Index\BlueGreenIndicesIncorrectlySetupException;
use Valantic\ElasticaBridgeBundle\Index\IndexInterface;
use Valantic\ElasticaBridgeBundle\Repository\IndexDocumentRepository;
use Valantic\ElasticaBridgeBundle\Repository\IndexRepository;
use Valantic\ElasticaBridgeBundle\Service\DocumentHelper;

class Index extends BaseCommand
{
    protected const ARGUMENT_INDEX = 'index';
    protected const OPTION_DELETE = 'delete';
    protected const OPTION_POPULATE = 'populate';
    protected const OPTION_CHECK = 'check';
    public static bool $isPopulating = false;
    protected ElasticsearchClient $esClient;
    protected DocumentHelper $documentHelper;
    protected IndexRepository $indexRepository;
    protected IndexDocumentRepository $indexDocumentRepository;

    public function __construct(
        IndexRepository $indexRepository,
        IndexDocumentRepository $indexDocumentRepository,
        ElasticsearchClient $esClient,
        DocumentHelper $documentHelper
    )
    {
        parent::__construct();
        $this->esClient = $esClient;
        $this->documentHelper = $documentHelper;
        $this->indexRepository = $indexRepository;
        $this->indexDocumentRepository = $indexDocumentRepository;
    }

    protected function configure(): void
    {
        $this->setName(self::COMMAND_NAMESPACE . 'index')
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
                self::OPTION_CHECK,
                'c',
                InputOption::VALUE_NONE,
                'Perform post-populate checks'
            );
    }

    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        parent::initialize($input, $output);

        if ($this->input->getOption(self::OPTION_CHECK) && !$this->input->getOption(self::OPTION_POPULATE)) {
            $this->output->writeln(sprintf('<error>--%s without --%s has no effect</error>', self::OPTION_CHECK, self::OPTION_POPULATE));
            $this->output->writeln('');
        }
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $skippedIndices = [];

        foreach ($this->indexRepository->all() as $indexConfig) {
            if (
                !empty($this->input->getArgument(self::ARGUMENT_INDEX)) &&
                !in_array($indexConfig->getName(), $this->input->getArgument(self::ARGUMENT_INDEX), true)
            ) {
                $skippedIndices[] = $indexConfig->getName();
                continue;
            }

            $this->output->writeln(sprintf('<info>Index: %s</info>', $indexConfig->getName()));

            $index = $this->esClient->getIndex($indexConfig->getName());
            $currentIndex = $index;
            $this->ensureCorrectIndexSetup($indexConfig);

            if ($indexConfig->usesBlueGreenIndices()) {
                $currentIndex = $indexConfig->getBlueGreenInactiveElasticaIndex();
            }

            if ($this->input->getOption(self::OPTION_POPULATE)) {

                if ($indexConfig->usesBlueGreenIndices()) {
                    $this->output->writeln('<comment>-> Re-created inactive blue/green index</comment>');
                    $currentIndex->delete();
                    $currentIndex->create($indexConfig->getCreateArguments());
                }

                $this->populateIndex($indexConfig, $currentIndex);

                $currentIndex->refresh();
                $indexCount = $currentIndex->count();
                $this->output->writeln(sprintf('<comment>-> %d documents</comment>', $indexCount));

                if ($indexCount > 0 && $this->input->getOption(self::OPTION_CHECK)) {
                    $this->checkRandomDocument($currentIndex, $indexConfig);
                }
            }

            if ($indexConfig->usesBlueGreenIndices()) {
                $oldIndex = $indexConfig->getBlueGreenActiveElasticaIndex();
                $newIndex = $indexConfig->getBlueGreenInactiveElasticaIndex();

                $newIndex->flush();
                $oldIndex->removeAlias($indexConfig->getName());
                $newIndex->addAlias($indexConfig->getName());
                $oldIndex->flush();
            }
            $this->output->writeln('');
        }

        if (count($skippedIndices)) {
            $this->output->writeln('');
            $this->output->writeln(sprintf('<info>Skipped the following indices: %s</info>', implode(', ', $skippedIndices)));
        }

        return 0;
    }

    protected function populateIndex(IndexInterface $indexConfig, ElasticaIndex $index): void
    {
        self::$isPopulating = true;
        ProgressBar::setFormatDefinition('custom', "%percent%%\t%remaining%\t%memory%\n%message%");

        $progressBar = new ProgressBar($this->output, 1);
        $progressBar->setMessage('');
        $progressBar->setFormat('custom');

        foreach ($indexConfig->getAllowedDocuments() as $indexDocument) {
            $progressBar->setProgress(0);
            $progressBar->setMessage($indexDocument);

            $indexDocumentInstance = $this->indexDocumentRepository->get($indexDocument);
            $listing = $indexDocumentInstance->getListingInstance($indexConfig);
            $listingCount = $listing->count();
            $progressBar->setMaxSteps($listingCount > 0 ? $listingCount : 1);
            $esDocuments = [];

            for ($batchNumber = 0; $batchNumber < ceil($listingCount / $indexConfig->getBatchSize()); $batchNumber++) {
                $listing->setOffset($batchNumber * $indexConfig->getBatchSize());
                $listing->setLimit($indexConfig->getBatchSize());

                foreach ($listing as $dataObject) {
                    $progressBar->advance();

                    if (!$indexDocumentInstance->shouldIndex($dataObject)) {
                        continue;
                    }

                    $esDocuments[] = $this->documentHelper->elementToIndexDocument($indexDocumentInstance, $dataObject);

                    if (count($esDocuments) > $indexConfig->getBatchSize()) {
                        $index->addDocuments($esDocuments);
                        $esDocuments = [];
                    }
                }
            }

            if (count($esDocuments) > 0) {
                $index->addDocuments($esDocuments);
            }

            if ($indexConfig->refreshIndexAfterEveryIndexDocumentWhenPopulating()) {
                $index->refresh();
            }
        }

        $progressBar->finish();
        $this->output->writeln('');
        self::$isPopulating = false;
    }

    protected function ensureCorrectIndexSetup(IndexInterface $indexConfig): void
    {
        if ($indexConfig->usesBlueGreenIndices()) {
            $this->ensureCorrectBlueGreenIndexSetup($indexConfig);

            return;
        }

        $this->ensureCorrectSimpleIndexSetup($indexConfig);
    }

    protected function ensureCorrectSimpleIndexSetup(IndexInterface $indexConfig): void
    {
        $index = $indexConfig->getElasticaIndex();

        if ($this->input->getOption(self::OPTION_DELETE) && $index->exists()) {
            $index->delete();
            $this->output->writeln('<comment>-> Deleted index</comment>');
        }

        if (!$index->exists()) {
            $index->create($indexConfig->getCreateArguments());
            $this->output->writeln('<comment>-> Created index</comment>');
        }
    }

    protected function ensureCorrectBlueGreenIndexSetup(IndexInterface $indexConfig): void
    {
        $shouldDelete = $this->input->getOption(self::OPTION_DELETE);

        foreach (IndexInterface::INDEX_SUFFIXES as $suffix) {
            $name = $indexConfig->getName() . $suffix;
            $aliasIndex = $this->esClient->getIndex($name);

            if ($shouldDelete && $aliasIndex->exists()) {
                $aliasIndex->delete();
            }

            if (!$aliasIndex->exists()) {
                $aliasIndex->create($indexConfig->getCreateArguments());
            }
        }

        try {
            $indexConfig->getBlueGreenActiveSuffix();
        } catch (BlueGreenIndicesIncorrectlySetupException $exception) {
            $this->esClient->getIndex($indexConfig->getName() . IndexInterface::INDEX_SUFFIX_BLUE)->addAlias($indexConfig->getName());
        }

        $this->output->writeln('<comment>-> Ensured indices are correctly set up with alias</comment>');
    }

    protected function checkRandomDocument(ElasticaIndex $index, IndexInterface $indexConfig): void
    {
        $esDocs = $index->search();
        $esDoc = $esDocs[rand(0, $esDocs->count() - 1)]->getDocument();
        $indexDocumentInstance = $indexConfig->getIndexDocumentInstance($esDoc);
        $this->output->writeln(sprintf('<comment>-> ES %s -> %s %s</comment>', $esDoc->getId(), $indexDocumentInstance ? $indexDocumentInstance->getPimcoreElement($esDoc)->getType() : 'FAILED', $indexDocumentInstance ? $indexDocumentInstance->getPimcoreElement($esDoc)->getId() : 'FAILED'));
    }
}
