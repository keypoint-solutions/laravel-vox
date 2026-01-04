<?php

namespace KeypointSolutions\LaravelVox\Translation\Drivers;

use KeypointSolutions\LaravelVox\Translation\TranslationPromptBuilder;

class TranslationDriverFactory
{
    public function make(): TranslationDriver
    {
        $driver = config('vox.translate.driver', 'openai');

        if ($driver === 'null') {
            return new NullTranslationDriver();
        }

        return new OpenAiTranslationDriver(app(TranslationPromptBuilder::class));
    }
}
