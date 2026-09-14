<?php

namespace KeypointSolutions\LaravelVox\Translation;

class PublishResult
{
    /**
     * @param  array<int, string>  $files
     * @param  array<int, string>  $publishedValues
     */
    public function __construct(
        private int $values,
        private array $files,
        private int $incompleteTranslations,
        private int $orphanTranslations,
        private array $frontendFiles = [],
        private array $publishedValues = [],
        private array $deletedKeys = [],
    ) {}

    /** @return array<int, array{group: string|null, key: string}> */
    public function deletedKeys(): array
    {
        return $this->deletedKeys;
    }

    public function values(): int
    {
        return $this->values;
    }

    /**
     * @return array<int, string>
     */
    public function publishedValues(): array
    {
        return $this->publishedValues;
    }

    /**
     * @return array<int, string>
     */
    public function files(): array
    {
        return $this->files;
    }

    public function fileCount(): int
    {
        return count($this->files);
    }

    public function skippedTranslations(): int
    {
        return $this->incompleteTranslations + $this->orphanTranslations;
    }

    public function incompleteTranslations(): int
    {
        return $this->incompleteTranslations;
    }

    public function orphanTranslations(): int
    {
        return $this->orphanTranslations;
    }

    /**
     * @return array<int, string>
     */
    public function frontendFiles(): array
    {
        return $this->frontendFiles;
    }

    public function frontendFileCount(): int
    {
        return count($this->frontendFiles);
    }
}
