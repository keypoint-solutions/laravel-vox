<?php

namespace KeypointSolutions\LaravelVox\Tests\Support;

use KeypointSolutions\LaravelVox\Translation\Drivers\TranslationDriver;

/**
 * Stands in for an AI provider in tests by returning the source wording unchanged.
 */
final class EchoTranslationDriver implements TranslationDriver
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
        return $text;
    }
}
