<?php

namespace KeypointSolutions\LaravelVox\Tests\Support;

use KeypointSolutions\LaravelVox\Translation\Drivers\TranslationDriver;

final class LocaleProvisionTranslationDriver implements TranslationDriver
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function translate(
        string $text,
        string $sourceLocale,
        string $targetLocale,
        array $context = []
    ): string {
        return "{$targetLocale}: {$text}";
    }
}
