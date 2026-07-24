<?php

namespace KeypointSolutions\LaravelVox\Translation\Drivers;

class NullTranslationDriver implements TranslationDriver
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function translate(string $text, string $sourceLocale, string $targetLocale, array $context = []): string
    {
        return $text;
    }
}
