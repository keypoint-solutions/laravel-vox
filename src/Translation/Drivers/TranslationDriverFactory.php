<?php

namespace KeypointSolutions\LaravelVox\Translation\Drivers;

use InvalidArgumentException;

class TranslationDriverFactory
{
    public function make(): TranslationDriver
    {
        $driver = (string) config('vox.translate.driver', 'openai');

        if ($driver === 'null') {
            return app(NullTranslationDriver::class);
        }

        if ($driver === 'openai') {
            return app(OpenAiTranslationDriver::class);
        }

        if (is_a($driver, TranslationDriver::class, true)) {
            return app($driver);
        }

        throw new InvalidArgumentException("Unsupported translation driver [{$driver}].");
    }
}
