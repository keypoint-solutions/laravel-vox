<?php

namespace KeypointSolutions\LaravelVox\Translation;

class SyncResult
{
    private int $translations = 0;

    private int $changedTranslations = 0;

    private int $reopenedTranslations = 0;

    private int $orphanTranslations = 0;

    private int $addedLanguageKeys = 0;

    private int $removedLanguageKeys = 0;

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

    public function setOrphanTranslations(int $orphanTranslations): void
    {
        $this->orphanTranslations = $orphanTranslations;
    }

    public function orphanTranslations(): int
    {
        return $this->orphanTranslations;
    }

    public function setLanguageFileChanges(int $addedLanguageKeys, int $removedLanguageKeys): void
    {
        $this->addedLanguageKeys = $addedLanguageKeys;
        $this->removedLanguageKeys = $removedLanguageKeys;
    }

    public function addedLanguageKeys(): int
    {
        return $this->addedLanguageKeys;
    }

    public function removedLanguageKeys(): int
    {
        return $this->removedLanguageKeys;
    }
}
