<?php

namespace Tests\Support;

use KeypointSolutions\LaravelVox\Translation\Drivers\TranslationDriver;

final class BrowserTranslationDriver implements TranslationDriver
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function translate(string $text, string $sourceLocale, string $targetLocale, array $context = []): string
    {
        return "AI {$targetLocale}: {$text}";
    }
}
