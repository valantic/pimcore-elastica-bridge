<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Model;

class CleanupResult
{
    /**
     * @param array<string,string[]> $removedAliases index name => aliases removed from (or, in a dry run, that would be removed from) it
     * @param string[] $deletedIndices names of indices deleted (or, in a dry run, that would be deleted)
     * @param array<string,string> $errors index name => error message
     */
    public function __construct(
        private readonly bool $dryRun,
        private readonly array $removedAliases,
        private readonly array $deletedIndices,
        private readonly array $errors,
    ) {
    }

    public function isDryRun(): bool
    {
        return $this->dryRun;
    }

    /**
     * @return array<string,string[]>
     */
    public function getRemovedAliases(): array
    {
        return $this->removedAliases;
    }

    /**
     * @return string[]
     */
    public function getDeletedIndices(): array
    {
        return $this->deletedIndices;
    }

    /**
     * @return array<string,string>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }
}
