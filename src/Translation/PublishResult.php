<?php

namespace KeypointSolutions\LaravelVox\Translation;

class PublishResult
{
    /**
     * @param  array<int, string>  $files
     */
    public function __construct(
        private int $values,
        private array $files,
        private int $incompleteTranslations,
        private int $protectedTranslations,
        private int $orphanTranslations,
    ) {}

    public function values(): int
    {
        return $this->values;
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
        return $this->incompleteTranslations
            + $this->protectedTranslations
            + $this->orphanTranslations;
    }

    public function incompleteTranslations(): int
    {
        return $this->incompleteTranslations;
    }

    public function protectedTranslations(): int
    {
        return $this->protectedTranslations;
    }

    public function orphanTranslations(): int
    {
        return $this->orphanTranslations;
    }
}
