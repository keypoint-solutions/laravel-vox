<?php

namespace KeypointSolutions\LaravelVox\Translation;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use KeypointSolutions\LaravelVox\Models\VoxTranslation;
use KeypointSolutions\LaravelVox\Models\VoxTranslationValue;
use KeypointSolutions\LaravelVox\Support\VoxLocaleResolver;
use RuntimeException;

class TranslationPublisher
{
    public function __construct(
        private TranslationFileRepository $files,
        private VoxLocaleResolver $localeResolver,
        private TranslationFileValidator $validator,
        private FrontendTranslationArtifacts $frontendArtifacts,
    ) {}

    public function publish(): PublishResult
    {
        $langPath = $this->files->langPath();
        $stagingPath = storage_path('vox/publish-'.Str::uuid());
        $this->validator->validateDirectory($langPath);
        File::makeDirectory($stagingPath, 0755, true);

        try {
            if (File::isDirectory($langPath) && ! File::copyDirectory($langPath, $stagingPath)) {
                throw new RuntimeException('Unable to prepare translation files for publishing.');
            }

            $stagedResult = $this->publishTo($stagingPath);
            $publishedFiles = [];

            foreach ($stagedResult->files() as $stagedFile) {
                $prefix = rtrim($stagingPath, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

                if (! str_starts_with($stagedFile, $prefix)) {
                    throw new RuntimeException('A generated translation file escaped the publishing directory.');
                }

                $relativePath = substr($stagedFile, strlen($prefix));
                $destination = rtrim($langPath, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$relativePath;
                File::ensureDirectoryExists(dirname($destination));

                if (! File::copy($stagedFile, $destination)) {
                    throw new RuntimeException("Unable to publish translation file [{$relativePath}].");
                }

                $publishedFiles[] = $destination;
            }

            $frontendFiles = config('vox.frontend.runtime.enabled', false)
                ? $this->frontendArtifacts->publish($langPath)
                : [];

            DB::connection(config('vox.database.connection', 'vox'))->transaction(function () use ($stagedResult): void {
                foreach ($stagedResult->publishedValues() as $id => $value) {
                    $current = VoxTranslationValue::query()->lockForUpdate()->find($id);

                    if ($current !== null && $current->value === $value) {
                        $current->timestamps = false;
                        $current->is_pending_publish = false;
                        $current->save();
                    }
                }
            });

            return new PublishResult(
                $stagedResult->values(),
                $publishedFiles,
                $stagedResult->incompleteTranslations(),
                $stagedResult->orphanTranslations(),
                $frontendFiles,
            );
        } finally {
            File::deleteDirectory($stagingPath);
        }
    }

    public function publishTo(string $langPath): PublishResult
    {
        $this->validator->validateDirectory($langPath);
        $result = $this->publishUsing($this->files->forPath($langPath));
        $this->validator->validateDirectory($langPath);

        return $result;
    }

    private function publishUsing(TranslationFileRepository $files): PublishResult
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
        $publishedValues = [];
        $incompleteTranslations = 0;
        $orphanTranslations = 0;

        $translations = VoxTranslation::query()
            ->where('status', 'approved')
            ->with('values')
            ->orderBy('group')
            ->orderBy('key')
            ->get();

        foreach ($translations as $translation) {
            if ($translation->is_orphan) {
                $orphanTranslations++;

                continue;
            }

            $values = $translation->values->keyBy('locale');
            $isComplete = collect($locales)->every(function (string $locale) use ($values, $prefix): bool {
                $value = $values->get($locale)?->value;

                return is_string($value) && $value !== '' && ! Str::startsWith($value, $prefix);
            });

            if (! $isComplete) {
                $incompleteTranslations++;

                continue;
            }

            foreach ($locales as $locale) {
                $value = $values->get($locale)?->value;

                if (! is_string($value)) {
                    continue;
                }

                if ($values->get($locale)->is_pending_publish) {
                    $publishedValues[$values->get($locale)->id] = $value;
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
                $existing = $files->loadGroup($locale, $group);
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

                $files->saveGroup(
                    $locale,
                    $group,
                    $updated,
                    [],
                    $files->loadLineComments($locale, $group),
                    array_values($files->loadObsoleteComments($locale, $group))
                );
                $valueCount += $changedValues;
                $changedFiles[] = $files->groupPath($locale, $group);
            }
        }

        foreach ($jsonUpdates as $locale => $namespaces) {
            foreach ($namespaces as $namespace => $updates) {
                $resolvedNamespace = $namespace !== '' ? $namespace : null;
                $existing = $files->loadJson($locale, $resolvedNamespace);
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

                $files->saveJson($locale, $updated, $resolvedNamespace);
                $valueCount += $changedValues;
                $changedFiles[] = $files->jsonPath($locale, $resolvedNamespace);
            }
        }

        sort($changedFiles);

        return new PublishResult(
            $valueCount,
            $changedFiles,
            $incompleteTranslations,
            $orphanTranslations,
            publishedValues: $publishedValues,
        );
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
