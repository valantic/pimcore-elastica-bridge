<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Command;

use Elastica\Index as ElasticaIndex;
use Elastica\Request;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Process\Process;
use Valantic\ElasticaBridgeBundle\Elastica\Client\ElasticsearchClient;
use Valantic\ElasticaBridgeBundle\Enum\IndexBlueGreenSuffix;
use Valantic\ElasticaBridgeBundle\Exception\Index\BlueGreenIndicesIncorrectlySetupException;
use Valantic\ElasticaBridgeBundle\Index\IndexInterface;
use Valantic\ElasticaBridgeBundle\Repository\DocumentRepository;
use Valantic\ElasticaBridgeBundle\Repository\IndexRepository;
use Valantic\ElasticaBridgeBundle\Service\DocumentHelper;

class Index extends BaseCommand
{
    protected const ARGUMENT_INDEX = 'index';
    protected const OPTION_DELETE = 'delete';
    protected const OPTION_POPULATE = 'populate';
    public static bool $isPopulating = false;

    public function __construct(
        protected IndexRepository $indexRepository,
        protected DocumentRepository $documentRepository,
        protected ElasticsearchClient $esClient,
        protected DocumentHelper $documentHelper,
        protected KernelInterface $kernel,
    ) {
        parent::__construct();
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
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $skippedIndices = [];

        foreach ($this->indexRepository->flattened() as $indexConfig) {
            if (
                !empty($this->input->getArgument(self::ARGUMENT_INDEX))
                && !in_array($indexConfig->getName(), $this->input->getArgument(self::ARGUMENT_INDEX), true)
            ) {
                $skippedIndices[] = $indexConfig->getName();

                continue;
            }

            $this->processIndex($indexConfig);
        }

        if (count($skippedIndices) > 0) {
            $this->output->writeln('');
            $this->output->writeln(sprintf('<info>Skipped the following indices: %s</info>', implode(', ', $skippedIndices)));
        }

        return self::SUCCESS;
    }

    protected function processIndex(IndexInterface $indexConfig): void
    {
        $this->output->writeln(sprintf('<info>Index: %s</info>', $indexConfig->getName()));

        $index = $this->esClient->getIndex($indexConfig->getName());
        $currentIndex = $index;
        $this->ensureCorrectIndexSetup($indexConfig);

        if ($indexConfig->usesBlueGreenIndices()) {
            $currentIndex = $indexConfig->getBlueGreenInactiveElasticaIndex();
        }

        if ($this->input->getOption(self::OPTION_POPULATE) === true) {
            if ($indexConfig->usesBlueGreenIndices()) {
                $this->output->writeln('<comment>-> Re-created inactive blue/green index</comment>');
                $currentIndex->delete();
                $currentIndex->create($indexConfig->getCreateArguments());
            }

            $this->populateIndex($indexConfig, $currentIndex);

            $currentIndex->refresh();
            $indexCount = $currentIndex->count();
            $this->output->writeln(sprintf('<comment>-> %d documents</comment>', $indexCount));

            if ($indexConfig->usesBlueGreenIndices()) {
                $oldIndex = $indexConfig->getBlueGreenActiveElasticaIndex();
                $newIndex = $indexConfig->getBlueGreenInactiveElasticaIndex();

                $newIndex->flush();
                $oldIndex->removeAlias($indexConfig->getName());
                $newIndex->addAlias($indexConfig->getName());
                $oldIndex->flush();

                $this->output->writeln(sprintf('<comment>-> %s is now active</comment>', $newIndex->getName()));
            }
        }

        $this->output->writeln('');
    }

    protected function populateIndex(IndexInterface $indexConfig, ElasticaIndex $esIndex): void
    {
        self::$isPopulating = true;
        $process = new Process(
            [
                'bin/console', self::COMMAND_NAMESPACE . 'populate-index',
                '--config', $indexConfig->getName(),
                '--index', $esIndex->getName(),
            ],
            $this->kernel->getProjectDir(),
            timeout: null
        );

        $process->run(function($type, $buffer): void {
            if ($type === Process::ERR && $this->output instanceof ConsoleOutput) {
                $this->output->getErrorOutput()->write($buffer);
            } else {
                $this->output->write($buffer);
            }
        });
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

        if ($this->input->getOption(self::OPTION_DELETE) === true && $index->exists()) {
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
        $shouldDelete = $this->input->getOption(self::OPTION_DELETE) === true;

        $nonAliasIndex = $this->esClient->getIndex($indexConfig->getName());

        // In case an index with the same name as the blue/green alias exists, delete it
        if (
            $nonAliasIndex->exists()
            && !$this->esClient->request('_alias/' . $indexConfig->getName(), Request::HEAD)->isOk()
        ) {
            $nonAliasIndex->delete();
        }

        foreach (IndexBlueGreenSuffix::cases() as $suffix) {
            $name = $indexConfig->getName() . $suffix->value;
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
        } catch (BlueGreenIndicesIncorrectlySetupException) {
            $this->esClient->getIndex($indexConfig->getName() . IndexBlueGreenSuffix::BLUE->value)->addAlias($indexConfig->getName());
        }

        $this->output->writeln('<comment>-> Ensured indices are correctly set up with alias</comment>');
    }
}
