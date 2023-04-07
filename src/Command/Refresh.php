<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Command;

use Pimcore\Event\Model\AssetEvent;
use Pimcore\Event\Model\DataObjectEvent;
use Pimcore\Event\Model\DocumentEvent;
use Pimcore\Model\Asset;
use Pimcore\Model\DataObject\Concrete;
use Pimcore\Model\Document;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;
use Valantic\ElasticaBridgeBundle\EventListener\Pimcore\Asset as AssetListener;
use Valantic\ElasticaBridgeBundle\EventListener\Pimcore\DataObject as DataObjectListener;
use Valantic\ElasticaBridgeBundle\EventListener\Pimcore\Document as DocumentListener;

class Refresh extends BaseCommand
{
    protected const OPTION_ASSETS = 'assets';
    protected const OPTION_DOCUMENTS = 'documents';
    protected const OPTION_OBJECTS = 'objects';

    public function __construct(
        protected AssetListener $assetListener,
        protected DataObjectListener $dataObjectListener,
        protected DocumentListener $documentListener,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName(self::COMMAND_NAMESPACE . 'refresh')
            ->setDescription('Refresh one or more Elasticsearch documents')
            ->addOption(
                self::OPTION_ASSETS,
                'a',
                InputOption::VALUE_IS_ARRAY | InputOption::VALUE_OPTIONAL,
                'IDs of assets to refresh',
                []
            )
            ->addOption(
                self::OPTION_DOCUMENTS,
                'd',
                InputOption::VALUE_IS_ARRAY | InputOption::VALUE_OPTIONAL,
                'IDs of documents to refresh',
                []
            )
            ->addOption(
                self::OPTION_OBJECTS,
                'o',
                InputOption::VALUE_IS_ARRAY | InputOption::VALUE_OPTIONAL,
                'IDs of objects to refresh',
                []
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->output->writeln('Assets');
        $this->handle(self::OPTION_ASSETS);

        $this->output->writeln('Documents');
        $this->handle(self::OPTION_DOCUMENTS);

        $this->output->writeln('Objects');
        $this->handle(self::OPTION_OBJECTS);

        return self::SUCCESS;
    }

    /**
     * @param self::OPTION_* $optionName
     */
    private function handle(
        string $optionName,
    ): void {
        $listener = match ($optionName) {
            self::OPTION_ASSETS => $this->assetListener,
            self::OPTION_DOCUMENTS => $this->documentListener,
            self::OPTION_OBJECTS => $this->dataObjectListener,
        };

        $eventClass = match ($optionName) {
            self::OPTION_ASSETS => AssetEvent::class,
            self::OPTION_DOCUMENTS => DocumentEvent::class,
            self::OPTION_OBJECTS => DataObjectEvent::class,
        };

        $objClass = match ($optionName) {
            self::OPTION_ASSETS => Asset::class,
            self::OPTION_DOCUMENTS => Document::class,
            self::OPTION_OBJECTS => Concrete::class,
        };

        foreach ($this->input->getOption($optionName) as $id) {
            $this->output->writeln($id);
            $element = $objClass::getById($id);

            if ($element === null) {
                $this->output->writeln(sprintf('-> ID %d of type %s not found, skipped', $id, $this->getShortName($objClass)));

                continue;
            }

            $listener->updated(new $eventClass($element));
        }
        $this->output->writeln('');
    }

    /**
     * @param class-string $className
     */
    private function getShortName(string $className): string
    {
        return basename(str_replace('\\', '/', $className));
    }
}
