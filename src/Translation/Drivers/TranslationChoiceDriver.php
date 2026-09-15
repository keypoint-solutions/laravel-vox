<?php

namespace KeypointSolutions\LaravelVox\Translation\Drivers;

interface TranslationChoiceDriver
{
    /**
     * @param  array{local: string, incoming: string, locale: string, default_locale: string, default_value: string|null, key: string, missing_prefix: string}  $context
     * @return array{choice: 'local'|'incoming', reason: string}
     */
    public function choose(array $context): array;

    /**
     * @param  array<int, array<string, mixed>>  $contexts
     * @return array<int, array{choice: string, reason: string}>
     */
    public function chooseMany(array $contexts): array;
}
