<?php

namespace KeypointSolutions\LaravelVox\Translation;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Schema;
use KeypointSolutions\LaravelVox\Models\VoxTranslation;
use KeypointSolutions\LaravelVox\Support\VoxDynamicKeyRegistry;

class TranslationFileUpdater
{
    private array $pendingDeletionKeys = [];

    public function __construct(
        private TranslationFileRepository $files,
        private VoxDynamicKeyRegistry $dynamicKeys
    ) {}

    /**
     * @param  array<string, array<string, mixed>>  $scanResults
     * @param  array<int, string>  $locales
     */
    public function updateFromScan(array $scanResults, array $locales, string $baseLocale): TranslationUpdateResult
    {
        return app(TranslationFileTransaction::class)->run(fn (): TranslationUpdateResult => $this->updateFiles($scanResults, $locales, $baseLocale));
    }

    /**
     * @param  array<string, array<string, mixed>>  $scanResults
     * @param  array<int, string>  $locales
     */
    private function updateFiles(array $scanResults, array $locales, string $baseLocale): TranslationUpdateResult
    {
        $this->pendingDeletionKeys = [];
        $connection = config('vox.database.connection', 'vox');
        if (Schema::connection($connection)->hasTable('vox_translations') && Schema::connection($connection)->hasColumn('vox_translations', 'is_pending_delete')) {
            foreach (VoxTranslation::query()->where('is_pending_delete', true)->get() as $pendingDeletion) {
                $this->pendingDeletionKeys[$this->dynamicKeys->fullKey($pendingDeletion->key, $pendingDeletion->group)] = true;
            }
        }
        $groupedKeys = [];
        $jsonKeys = [];
        $namespacedJsonKeys = [];

        foreach ($scanResults as $entry) {
            if (isset($this->pendingDeletionKeys[$this->dynamicKeys->fullKey($entry['key'], $entry['group'])])) {
                continue;
            }
            $group = $entry['group'];

            if ($group === null) {
                $key = $entry['key'];

                if (is_string($key) && str_contains($key, '::')) {
                    [$namespace, $jsonKey] = explode('::', $key, 2);

                    if ($namespace !== '' && $jsonKey !== '') {
                        $namespacedJsonKeys[$namespace][] = $jsonKey;

                        continue;
                    }
                }

                $jsonKeys[] = $key;

                continue;
            }

            $groupedKeys[$group][] = $entry['key'];
        }

        if ($this->obsoleteAction() === 'discard') {
            foreach ($locales as $locale) {
                foreach ($this->files->groups($locale) as $group) {
                    $groupedKeys[$group] ??= [];
                }
                foreach ($this->files->jsonNamespaces($locale) as $namespace) {
                    if ($namespace !== null) {
                        $namespacedJsonKeys[$namespace] ??= [];
                    }
                }
            }
        }

        $jsonKeys = array_values(array_unique($jsonKeys));
        $result = new TranslationUpdateResult;

        foreach ($groupedKeys as $group => $keys) {
            $keys = array_values(array_unique($keys));
            $baseExisting = $this->files->loadGroup($baseLocale, $group);
            $baseFlat = Arr::dot($baseExisting);
            $keys = array_values(array_unique(array_merge($keys, $this->dynamicKeysFromBase($baseFlat, $group))));
            $lineComments = $this->buildLineComments($scanResults, $group);

            foreach ($locales as $locale) {
                $existing = $this->files->loadGroup($locale, $group);
                $existing = app(TranslationGroupFormat::class)->normalize($existing);
                $flatExisting = Arr::dot($existing);
                $updated = $existing;
                $commented = [];
                $rawCommented = $this->obsoleteAction() === 'comment'
                    ? $this->files->loadObsoleteComments($locale, $group)
                    : [];

                foreach ($keys as $key) {
                    if ($this->hasKey($existing, $key)) {
                        continue;
                    }

                    $baseValue = $baseFlat[$key] ?? Arr::get($baseExisting, $key);
                    $value = $this->buildNewValue($key, $locale, $baseLocale, $group, $baseValue);
                    $this->setValue($updated, $key, $value, $existing);
                    $result->incrementAdded();
                }

                $obsoleteKeys = array_diff(array_keys($flatExisting), $keys);

                foreach ($obsoleteKeys as $obsoleteKey) {
                    $existsInBase = array_key_exists($obsoleteKey, $baseFlat);
                    if ($this->shouldKeepOrphanKey($locale, $baseLocale, $existsInBase)) {
                        continue;
                    }

                    if (isset($this->pendingDeletionKeys[$this->dynamicKeys->fullKey($obsoleteKey, $group)]) || $this->dynamicKeys->matches($obsoleteKey, $group)) {
                        continue;
                    }

                    $value = $this->getValue($existing, $obsoleteKey, $flatExisting);

                    if ($this->obsoleteAction() === 'discard') {
                        $this->forgetValue($updated, $obsoleteKey, $existing, $flatExisting);
                        $result->incrementRemoved();

                        continue;
                    }

                    if ($this->obsoleteAction() === 'comment') {
                        $this->forgetValue($updated, $obsoleteKey, $existing, $flatExisting);
                        $commented[$obsoleteKey] = $value;
                        $result->incrementRemoved();
                    }
                }

                if ($rawCommented !== []) {
                    foreach (array_keys($commented) as $commentedKey) {
                        unset($rawCommented[$commentedKey]);
                    }

                    foreach ($keys as $activeKey) {
                        unset($rawCommented[$activeKey]);
                    }
                }

                if ($updated === [] && $this->obsoleteAction() === 'discard') {
                    app(TranslationFileTransaction::class)->delete($this->files->groupPath($locale, $group));

                    continue;
                }

                $this->files->saveGroup(
                    $locale,
                    $group,
                    $updated,
                    $this->shouldComment($locale, $baseLocale) ? $commented : [],
                    $lineComments,
                    array_values($rawCommented)
                );
            }
        }

        $baseJson = $this->files->loadJson($baseLocale);

        foreach ($locales as $locale) {
            $existing = $this->files->loadJson($locale);
            $updated = $existing;

            foreach ($jsonKeys as $key) {
                if (array_key_exists($key, $existing)) {
                    continue;
                }

                $value = $this->buildNewValue($key, $locale, $baseLocale, null, $baseJson[$key] ?? null);
                $updated[$key] = $value;
                $result->incrementAdded();
            }

            $obsoleteKeys = array_diff(array_keys($existing), $jsonKeys);

            foreach ($obsoleteKeys as $obsoleteKey) {
                $existsInBase = array_key_exists($obsoleteKey, $baseJson);
                if ($this->shouldKeepOrphanKey($locale, $baseLocale, $existsInBase)) {
                    continue;
                }

                if (isset($this->pendingDeletionKeys[$obsoleteKey]) || $this->dynamicKeys->matches($obsoleteKey, null)) {
                    continue;
                }

                if ($this->obsoleteAction() === 'discard') {
                    unset($updated[$obsoleteKey]);
                    $result->incrementRemoved();
                }
            }

            if ($updated === [] && $this->obsoleteAction() === 'discard') {
                app(TranslationFileTransaction::class)->delete($this->files->jsonPath($locale));
            } else {
                $this->files->saveJson($locale, $updated);
            }
        }

        foreach ($namespacedJsonKeys as $namespace => $keys) {
            $keys = array_values(array_unique($keys));
            $baseJson = $this->files->loadJson($baseLocale, $namespace);

            foreach ($locales as $locale) {
                $existing = $this->files->loadJson($locale, $namespace);
                $updated = $existing;

                foreach ($keys as $key) {
                    if (array_key_exists($key, $existing)) {
                        continue;
                    }

                    $value = $this->buildNewValue($key, $locale, $baseLocale, null, $baseJson[$key] ?? null);
                    $updated[$key] = $value;
                    $result->incrementAdded();
                }

                $obsoleteKeys = array_diff(array_keys($existing), $keys);

                foreach ($obsoleteKeys as $obsoleteKey) {
                    $existsInBase = array_key_exists($obsoleteKey, $baseJson);
                    if ($this->shouldKeepOrphanKey($locale, $baseLocale, $existsInBase)) {
                        continue;
                    }

                    if (isset($this->pendingDeletionKeys[$namespace.'::'.$obsoleteKey]) || $this->dynamicKeys->matches($namespace.'::'.$obsoleteKey, null)) {
                        continue;
                    }

                    if ($this->obsoleteAction() === 'discard') {
                        unset($updated[$obsoleteKey]);
                        $result->incrementRemoved();
                    }
                }

                if ($updated === [] && $this->obsoleteAction() === 'discard') {
                    app(TranslationFileTransaction::class)->delete($this->files->jsonPath($locale, (string) $namespace));
                } else {
                    $this->files->saveJson($locale, $updated, (string) $namespace);
                }
            }
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $existing
     * @param  array<string, mixed>  $flatExisting
     */
    private function buildNewValue(string $key, string $locale, string $baseLocale, ?string $group, mixed $baseValue = null): string
    {
        $value = is_string($baseValue) && $baseValue !== ''
            ? $baseValue
            : $this->defaultValueForKey($key, $group);

        if ($locale !== $baseLocale) {
            $prefix = config('vox.parse.missing_translation_prefix', '🚩');
            $value = $prefix.$value;
        }

        return $value;
    }

    private function defaultValueForKey(string $key, ?string $group): string
    {
        if ($group === null) {
            return $key;
        }

        $label = $this->stripKeyPrefixes($key);

        return $label;
    }

    private function stripKeyPrefixes(string $key): string
    {
        $remaining = $key;

        while (true) {
            $dot = strpos($remaining, '.');

            if ($dot === false || $dot === strlen($remaining) - 1) {
                break;
            }

            $segment = substr($remaining, 0, $dot);

            if ($segment === '' || preg_match('/\s/', $segment) === 1) {
                break;
            }

            $remaining = substr($remaining, $dot + 1);
        }

        return $remaining;
    }

    /**
     * @param  array<string, mixed>  $existing
     * @param  array<string, mixed>  $flatExisting
     */
    private function setValue(array &$target, string $key, string $value, array $existing): void
    {
        app(TranslationGroupFormat::class)->set($target, $key, $value);
    }

    /**
     * @param  array<string, mixed>  $existing
     * @param  array<string, mixed>  $flatExisting
     */
    private function hasKey(array $existing, string $key): bool
    {
        return Arr::has($existing, $key) || array_key_exists($key, $existing);
    }

    /**
     * @param  array<string, mixed>  $existing
     * @param  array<string, mixed>  $flatExisting
     */
    private function getValue(array $existing, string $key, array $flatExisting): mixed
    {
        if (array_key_exists($key, $flatExisting)) {
            return $flatExisting[$key];
        }

        return Arr::get($existing, $key);
    }

    /**
     * @param  array<string, mixed>  $existing
     * @param  array<string, mixed>  $flatExisting
     */
    private function forgetValue(array &$target, string $key, array $existing, array $flatExisting): void
    {
        app(TranslationGroupFormat::class)->remove($target, $key);
    }

    private function obsoleteAction(): string
    {
        return config('vox.parse.obsolete', 'discard');
    }

    private function shouldComment(string $locale, string $baseLocale): bool
    {
        return $this->obsoleteAction() === 'comment';
    }

    private function shouldKeepOrphanKey(string $locale, string $baseLocale, bool $existsInBase): bool
    {
        if (! config('vox.parse.keep_orphan_other_locales_keys', true)) {
            return false;
        }

        return $locale !== $baseLocale && ! $existsInBase;
    }

    /**
     * @param  array<string, mixed>  $baseFlat
     * @return array<int, string>
     */
    private function dynamicKeysFromBase(array $baseFlat, string $group): array
    {
        $keys = [];

        foreach (array_keys($baseFlat) as $key) {
            if (! isset($this->pendingDeletionKeys[$this->dynamicKeys->fullKey($key, $group)]) && $this->dynamicKeys->matches($key, $group)) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /**
     * @param  array<string, array<string, mixed>>  $scanResults
     * @return array<string, array<int, string>>
     */
    private function buildLineComments(array $scanResults, string $group): array
    {
        if (! config('vox.parse.add_context_comments', false) && ! config('vox.parse.add_occurrence_comments', false)) {
            return [];
        }

        $comments = [];

        foreach ($scanResults as $entry) {
            if (($entry['group'] ?? null) !== $group) {
                continue;
            }

            $key = $entry['key'] ?? null;

            if (! is_string($key) || $key === '') {
                continue;
            }

            $occurrences = $entry['occurrences'] ?? [];

            if (! is_array($occurrences) || $occurrences === []) {
                continue;
            }

            $commentLines = [];
            $firstOccurrence = $occurrences[0] ?? null;
            $source = $entry['source'] ?? null;
            $source = is_string($source) ? $source : null;

            if (config('vox.parse.add_context_comments', false) && is_array($firstOccurrence)) {
                $commentLines[] = $this->buildContextComment($source, $firstOccurrence);
            }

            if (config('vox.parse.add_occurrence_comments', false)) {
                $occurrenceComment = $this->buildOccurrenceComment($occurrences);

                if ($occurrenceComment !== null) {
                    $commentLines[] = $occurrenceComment;
                }
            }

            $commentLines = array_values(array_filter($commentLines, fn (string $line) => $line !== ''));

            if ($commentLines !== []) {
                $comments[$key] = $commentLines;
            }
        }

        return $comments;
    }

    /**
     * @param  array<string, mixed>  $occurrence
     */
    private function buildContextComment(?string $source, array $occurrence): string
    {
        $before = trim((string) ($occurrence['before'] ?? ''));
        $after = trim((string) ($occurrence['after'] ?? ''));
        $placeholder = 'KEY';
        if (is_string($source) && $source !== '' && ! str_contains($before, $source) && ! str_contains($after, $source)) {
            $placeholder = $source.'(KEY)';
        }
        [$before, $after] = $this->stripMatchingQuotes($before, $after);

        if ($before === '' && $after === '') {
            return $placeholder;
        }

        return $this->joinContextPieces($before, $placeholder, $after);
    }

    /**
     * @param  array<int, array<string, mixed>>  $occurrences
     */
    private function buildOccurrenceComment(array $occurrences): ?string
    {
        $first = $occurrences[0] ?? null;

        if (! is_array($first)) {
            return null;
        }

        $file = $first['file'] ?? null;

        if (! is_string($file) || $file === '') {
            return null;
        }

        $line = $first['line'] ?? null;
        $location = $file;

        if (is_int($line)) {
            $location .= ':'.$line;
        } elseif (is_string($line) && ctype_digit($line)) {
            $location .= ':'.$line;
        }

        return $location;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function stripMatchingQuotes(string $before, string $after): array
    {
        $beforeTrimmed = rtrim($before);
        $afterTrimmed = ltrim($after);

        if ($beforeTrimmed === '' || $afterTrimmed === '') {
            return [$before, $after];
        }

        $beforeLast = substr($beforeTrimmed, -1);
        $afterFirst = substr($afterTrimmed, 0, 1);

        if (($beforeLast === '\'' || $beforeLast === '"') && $beforeLast === $afterFirst) {
            $beforeTrimmed = rtrim(substr($beforeTrimmed, 0, -1));
            $afterTrimmed = ltrim(substr($afterTrimmed, 1));
        }

        return [$beforeTrimmed, $afterTrimmed];
    }

    private function joinContextPieces(string $before, string $placeholder, string $after): string
    {
        $before = rtrim($before);
        $after = ltrim($after);
        $prefix = '';
        $suffix = '';

        if ($before !== '' && preg_match('/[A-Za-z0-9_]$/', $before) === 1) {
            $prefix = ' ';
        }

        if ($after !== '' && preg_match('/^[A-Za-z0-9_]/', $after) === 1) {
            $suffix = ' ';
        }

        return trim($before.$prefix.$placeholder.$suffix.$after);
    }
}
