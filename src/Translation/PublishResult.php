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
        private int $skippedTranslations,
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
        return $this->skippedTranslations;
    }
}
