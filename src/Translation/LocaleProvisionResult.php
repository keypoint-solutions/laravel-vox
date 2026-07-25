<?php

namespace KeypointSolutions\LaravelVox\Translation;

class LocaleProvisionResult
{
    public function __construct(
        public readonly string $locale,
        public readonly int $files,
        public readonly int $values,
        public readonly int $translatedValues,
    ) {}
}
