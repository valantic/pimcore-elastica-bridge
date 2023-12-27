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
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;
use Symfony\Component\Process\Process;
use Valantic\ElasticaBridgeBundle\Elastica\Client\ElasticsearchClient;
use Valantic\ElasticaBridgeBundle\Enum\IndexBlueGreenSuffix;
use Valantic\ElasticaBridgeBundle\Exception\Index\BlueGreenIndicesIncorrectlySetupException;
use Valantic\ElasticaBridgeBundle\Index\IndexInterface;
use Valantic\ElasticaBridgeBundle\Repository\ConfigurationRepository;
use Valantic\ElasticaBridgeBundle\Repository\IndexRepository;

class Index extends BaseCommand
{
    private const ARGUMENT_INDEX = 'index';
    private const OPTION_DELETE = 'delete';
    private const OPTION_POPULATE = 'populate';
    private const OPTION_LOCK_RELEASE = 'lock-release';
    public static bool $isPopulating = false;

    public function __construct(
        private readonly IndexRepository $indexRepository,
        private readonly ElasticsearchClient $esClient,
        private readonly KernelInterface $kernel,
        private readonly LockFactory $lockFactory,
        private readonly ConfigurationRepository $configurationRepository,
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
            )
            ->addOption(
                self::OPTION_LOCK_RELEASE,
                'l',
                InputOption::VALUE_NONE,
                'Force all indexing locks to be released'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $skippedIndices = [];

        foreach ($this->indexRepository->flattened() as $indexConfig) {
            if (
                is_array($this->input->getArgument(self::ARGUMENT_INDEX))
                && count($this->input->getArgument(self::ARGUMENT_INDEX)) > 0
                && !in_array($indexConfig->getName(), $this->input->getArgument(self::ARGUMENT_INDEX), true)
            ) {
                $skippedIndices[] = $indexConfig->getName();

                continue;
            }

            $lock = $this->getLock($indexConfig);

            if (!$lock->acquire()) {
                if ($this->input->getOption(self::OPTION_LOCK_RELEASE) === true) {
                    $lock->release();
                    $this->output->writeln(sprintf(
                        "\n<comment>Force-released lock for %s.</comment>\n",
                        $indexConfig->getName()
                    ));
                }

                if ($this->input->getOption(self::OPTION_LOCK_RELEASE) === false) {
                    $this->output->writeln(
                        sprintf(
                            "\n<comment>Lock for %s is held by another process.</comment>\n",
                            $indexConfig->getName(),
                        )
                    );

                    continue;
                }
            }

            try {
                $this->processIndex($indexConfig);
            } finally {
                $lock->release();
            }
        }

        if (count($skippedIndices) > 0) {
            $this->output->writeln('');
            $this->output->writeln(
                sprintf('<info>Skipped the following indices: %s</info>', implode(', ', $skippedIndices))
            );
        }

        return self::SUCCESS;
    }

    private function processIndex(IndexInterface $indexConfig): void
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

                $this->output->writeln(
                    sprintf('<comment>-> %s is now active</comment>', $newIndex->getName())
                );
            }
        }

        $this->output->writeln('');
    }

    private function populateIndex(IndexInterface $indexConfig, ElasticaIndex $esIndex): void
    {
        self::$isPopulating = true;
        $process = new Process(
            [
                'bin/console', self::COMMAND_NAMESPACE . 'populate-index',
                '--config', $indexConfig->getName(),
                '--index', $esIndex->getName(),
                ...array_filter([$this->output->isVerbose() ? '-v' : null,
                    $this->output->isVeryVerbose() ? '-vv' : null,
                    $this->output->isDebug() ? '-vvv' : null,
                ]),
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

    private function ensureCorrectIndexSetup(IndexInterface $indexConfig): void
    {
        if ($indexConfig->usesBlueGreenIndices()) {
            $this->ensureCorrectBlueGreenIndexSetup($indexConfig);

            return;
        }

        $this->ensureCorrectSimpleIndexSetup($indexConfig);
    }

    private function ensureCorrectSimpleIndexSetup(IndexInterface $indexConfig): void
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

    private function ensureCorrectBlueGreenIndexSetup(IndexInterface $indexConfig): void
    {
        $shouldDelete = $this->input->getOption(self::OPTION_DELETE) === true;

        $nonAliasIndex = $this->esClient->getIndex($indexConfig->getName());

        // In case an index with the same name as the blue/green alias exists, delete it
        if (
            $nonAliasIndex->exists()
            && !$this->esClient->request(Request::HEAD, '_alias/' . $indexConfig->getName())->asBool()
        ) {
            $nonAliasIndex->delete();
            $this->output->writeln('<comment>-> Deleted non-blue/green index to prepare for blue/green usage</comment>');
        }

        foreach (IndexBlueGreenSuffix::cases() as $suffix) {
            $name = $indexConfig->getName() . $suffix->value;
            $aliasIndex = $this->esClient->getIndex($name);

            if ($shouldDelete && $aliasIndex->exists()) {
                $aliasIndex->delete();
                $this->output->writeln('<comment>-> Deleted blue/green index with alias</comment>');
            }

            if (!$aliasIndex->exists()) {
                $aliasIndex->create($indexConfig->getCreateArguments());
                $this->output->writeln('<comment>-> Created blue/green index with alias</comment>');
            }
        }

        try {
            $indexConfig->getBlueGreenActiveSuffix();
        } catch (BlueGreenIndicesIncorrectlySetupException) {
            $this->esClient->getIndex($indexConfig->getName() . IndexBlueGreenSuffix::BLUE->value)
                ->addAlias($indexConfig->getName());
        }

        $this->output->writeln('<comment>-> Ensured indices are correctly set up with alias</comment>');
    }

    private function getLock(mixed $indexConfig): LockInterface
    {
        return $this->lockFactory
            ->createLock(
                __METHOD__ . '->' . $indexConfig->getName(),
                ttl: $this->configurationRepository->getIndexingLockTimeout()
            );
    }
}
