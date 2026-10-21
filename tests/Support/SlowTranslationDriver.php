<?php

namespace KeypointSolutions\LaravelVox\Tests\Support;

use Illuminate\Support\Carbon;
use KeypointSolutions\LaravelVox\Translation\Drivers\TranslationDriver;

/**
 * Takes twelve seconds of test time per call, so a request's time budget runs out after the second call.
 */
final class SlowTranslationDriver implements TranslationDriver
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
        Carbon::setTestNow(Carbon::now()->addSeconds(12));

        return "{$targetLocale}: {$text}";
    }
}
