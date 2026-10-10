<?php

namespace KeypointSolutions\LaravelVox\Translation\Locales;

class LocaleProvisionResult
{
    public function __construct(
        public readonly string $locale,
        public readonly int $files,
        public readonly int $values,
        public readonly int $translatedValues,
        public readonly ?string $translationFailure = null,
    ) {}
}
