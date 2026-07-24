<?php

namespace KeypointSolutions\LaravelVox\Translation;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use KeypointSolutions\LaravelVox\Models\VoxTranslation;
use KeypointSolutions\LaravelVox\Support\VoxLocaleResolver;

class TranslationPublisher
{
    public function __construct(
        private TranslationFileRepository $files,
        private VoxLocaleResolver $localeResolver,
    ) {}

    public function publish(): PublishResult
    {
        $locales = $this->localeResolver->resolveLocales();
        $baseLocale = $this->localeResolver->resolveBaseLocale($locales);

        if (! in_array($baseLocale, $locales, true)) {
            $locales[] = $baseLocale;
        }

        $locales = array_values(array_unique($locales));
        $prefix = (string) config('vox.parse.missing_translation_prefix', '🚩');
        $groupUpdates = [];
        $jsonUpdates = [];
        $valueCount = 0;
        $skippedTranslations = 0;

        $translations = VoxTranslation::query()
            ->where('status', 'approved')
            ->with('values')
            ->orderBy('group')
            ->orderBy('key')
            ->get();

        foreach ($translations as $translation) {
            $values = $translation->values->keyBy('locale');
            $isComplete = collect($locales)->every(function (string $locale) use ($values, $prefix): bool {
                $value = $values->get($locale)?->value;

                return is_string($value) && $value !== '' && ! Str::startsWith($value, $prefix);
            });

            if (! $isComplete) {
                $skippedTranslations++;

                continue;
            }

            foreach ($locales as $locale) {
                $value = $values->get($locale)?->value;

                if (! is_string($value)) {
                    continue;
                }

                if ($translation->group === null || $translation->group === 'json') {
                    [$namespace, $key] = $this->splitJsonKey($translation->key);
                    $jsonUpdates[$locale][$namespace ?? ''][$key] = $value;
                } else {
                    $groupUpdates[$locale][$translation->group][$translation->key] = $value;
                }
            }
        }

        $changedFiles = [];

        foreach ($groupUpdates as $locale => $groups) {
            foreach ($groups as $group => $updates) {
                $existing = $this->files->loadGroup($locale, $group);
                $updated = $existing;
                $changedValues = 0;

                foreach ($updates as $key => $value) {
                    if ($this->groupValue($existing, $key) === $value) {
                        continue;
                    }

                    $this->setGroupValue($updated, $existing, $key, $value);
                    $changedValues++;
                }

                if ($changedValues === 0) {
                    continue;
                }

                $this->files->saveGroup(
                    $locale,
                    $group,
                    $updated,
                    [],
                    $this->files->loadLineComments($locale, $group),
                    array_values($this->files->loadObsoleteComments($locale, $group))
                );
                $valueCount += $changedValues;
                $changedFiles[] = $this->files->groupPath($locale, $group);
            }
        }

        foreach ($jsonUpdates as $locale => $namespaces) {
            foreach ($namespaces as $namespace => $updates) {
                $resolvedNamespace = $namespace !== '' ? $namespace : null;
                $existing = $this->files->loadJson($locale, $resolvedNamespace);
                $updated = $existing;
                $changedValues = 0;

                foreach ($updates as $key => $value) {
                    if (($existing[$key] ?? null) === $value) {
                        continue;
                    }

                    $updated[$key] = $value;
                    $changedValues++;
                }

                if ($changedValues === 0) {
                    continue;
                }

                $this->files->saveJson($locale, $updated, $resolvedNamespace);
                $valueCount += $changedValues;
                $changedFiles[] = $this->files->jsonPath($locale, $resolvedNamespace);
            }
        }

        sort($changedFiles);

        return new PublishResult($valueCount, $changedFiles, $skippedTranslations);
    }

    /**
     * @return array{0: string|null, 1: string}
     */
    private function splitJsonKey(string $key): array
    {
        if (! str_contains($key, '::')) {
            return [null, $key];
        }

        [$namespace, $jsonKey] = explode('::', $key, 2);

        return [$namespace !== '' ? $namespace : null, $jsonKey];
    }

    /**
     * @param  array<string, mixed>  $existing
     */
    private function groupValue(array $existing, string $key): mixed
    {
        if (array_key_exists($key, $existing)) {
            return $existing[$key];
        }

        return Arr::get($existing, $key);
    }

    /**
     * @param  array<string, mixed>  $updated
     * @param  array<string, mixed>  $existing
     */
    private function setGroupValue(array &$updated, array $existing, string $key, string $value): void
    {
        $useNested = config('vox.parse.output', 'flat') === 'nested'
            || (config('vox.parse.preserve_existing_format', true) && Arr::has($existing, $key));

        if ($useNested) {
            Arr::set($updated, $key, $value);

            return;
        }

        $updated[$key] = $value;
    }
}
