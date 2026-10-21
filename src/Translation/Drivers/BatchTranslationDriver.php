<?php

namespace KeypointSolutions\LaravelVox\Translation\Drivers;

use Throwable;

/**
 * A driver that can translate several texts, into one or more target locales, in a single request.
 */
interface BatchTranslationDriver extends TranslationDriver
{
    /**
     * Results are keyed like the items. An item whose translation failed holds the exception, and an item that was
     * not reached is left out. A failure of the whole request is thrown.
     *
     * @param  array<array-key, array{text: string, target: string, context?: array<string, mixed>}>  $items
     * @return array<array-key, string|Throwable>
     */
    public function translateMany(array $items, string $sourceLocale): array;
}
