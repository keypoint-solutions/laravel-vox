<?php

namespace KeypointSolutions\LaravelVox\Translation\Drivers;

interface TranslationDriver
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function translate(string $text, string $sourceLocale, string $targetLocale, array $context = []): string;
}
