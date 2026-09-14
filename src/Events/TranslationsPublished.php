<?php

namespace KeypointSolutions\LaravelVox\Events;

use KeypointSolutions\LaravelVox\Translation\PublishResult;

class TranslationsPublished
{
    /** @param 'bundled'|'runtime' $frontendMode */
    public function __construct(
        public readonly string $frontendMode,
        public readonly PublishResult $result,
        public readonly bool $publishedOnly = false,
    ) {}
}
