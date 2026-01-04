<?php

namespace KeypointSolutions\LaravelVox\Translation;

class TranslationUpdateResult
{
    private int $added = 0;
    private int $removed = 0;

    public function incrementAdded(): void
    {
        $this->added++;
    }

    public function incrementRemoved(): void
    {
        $this->removed++;
    }

    public function added(): int
    {
        return $this->added;
    }

    public function removed(): int
    {
        return $this->removed;
    }
}
