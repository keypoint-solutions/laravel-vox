<?php

namespace KeypointSolutions\LaravelVox\Translation\Drivers;

class TranslationDriverFactory
{
    public function make(): TranslationDriver
    {
        $driver = config('vox.translate.driver', 'openai');

        if ($driver === 'null') {
            return new NullTranslationDriver;
        }

        return app(OpenAiTranslationDriver::class);
    }
}
