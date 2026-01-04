<?php

namespace KeypointSolutions\LaravelVox\Translation;

class SyncResult
{
    private int $translations = 0;

    public function incrementTranslations(): void
    {
        $this->translations++;
    }

    public function translations(): int
    {
        return $this->translations;
    }
}
