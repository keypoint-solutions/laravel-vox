<?php

namespace KeypointSolutions\LaravelVox\Translation\Drivers;

use InvalidArgumentException;
use KeypointSolutions\LaravelVox\Support\VoxConfig;

class TranslationDriverFactory
{
    public function make(): TranslationDriver
    {
        $driver = VoxConfig::translateDriver();

        if ($driver === 'null') {
            return app(NullTranslationDriver::class);
        }

        if ($driver === 'openai') {
            return app(OpenAiTranslationDriver::class);
        }

        if ($driver === 'claude') {
            return app(ClaudeTranslationDriver::class);
        }

        if (is_a($driver, TranslationDriver::class, true)) {
            return app($driver);
        }

        throw new InvalidArgumentException("Unsupported translation driver [{$driver}].");
    }
}
