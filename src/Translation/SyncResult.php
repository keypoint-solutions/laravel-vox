<?php

namespace KeypointSolutions\LaravelVox\Translation;

class SyncResult
{
    private int $translations = 0;

    private int $changedTranslations = 0;

    private int $reopenedTranslations = 0;

    public function incrementTranslations(): void
    {
        $this->translations++;
    }

    public function translations(): int
    {
        return $this->translations;
    }

    public function incrementChangedTranslations(): void
    {
        $this->changedTranslations++;
    }

    public function changedTranslations(): int
    {
        return $this->changedTranslations;
    }

    public function incrementReopenedTranslations(): void
    {
        $this->reopenedTranslations++;
    }

    public function reopenedTranslations(): int
    {
        return $this->reopenedTranslations;
    }
}
