<?php

namespace KeypointSolutions\LaravelVox\Translation;

use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use KeypointSolutions\LaravelVox\Events\TranslationsPublished;
use KeypointSolutions\LaravelVox\Models\VoxTranslation;
use KeypointSolutions\LaravelVox\Models\VoxTranslationValue;
use KeypointSolutions\LaravelVox\Support\VoxLocaleResolver;
use KeypointSolutions\LaravelVox\Support\VoxMutationLock;
use RuntimeException;

class TranslationPublisher
{
    public function __construct(
        private TranslationFileRepository $files,
        private VoxLocaleResolver $localeResolver,
        private TranslationFileValidator $validator,
        private FrontendTranslationArtifacts $frontendArtifacts,
    ) {}

    public function publish(?array $valueIds = null): PublishResult
    {
        return app(VoxMutationLock::class)->run(fn (): PublishResult => app(TranslationFileTransaction::class)->run(fn (): PublishResult => DB::connection(config('vox.database.connection', 'vox'))->transaction(function () use ($valueIds): PublishResult {
            $pending = $valueIds === null ? VoxTranslation::query()->where('is_pending_delete', true)->lockForUpdate()->get() : collect();

            return $this->publishPending($pending, $valueIds);
        })));
    }

    public function regenerate(): PublishResult
    {
        return app(VoxMutationLock::class)->run(fn (): PublishResult => app(TranslationFileTransaction::class)->run(
            fn (): PublishResult => DB::connection(config('vox.database.connection', 'vox'))->transaction(
                fn (): PublishResult => $this->publishPending(collect(), null, true)
            )
        ));
    }

    private function publishPending(Collection $pending, ?array $valueIds, bool $regenerate = false): PublishResult
    {
        $langPath = $this->files->langPath();
        $stagingPath = storage_path('vox/publish-'.Str::uuid());
        $this->validator->validateDirectory($langPath);
        File::makeDirectory($stagingPath, 0755, true);

        try {
            if (File::isDirectory($langPath) && ! File::copyDirectory($langPath, $stagingPath)) {
                throw new RuntimeException('Unable to prepare translation files for publishing.');
            }

            $stagedResult = $regenerate
                ? $this->publishUsing($this->files->forPath($stagingPath), null, true, true)
                : $this->publishUsing($this->files->forPath($stagingPath), $valueIds, false, $valueIds === null);
            $this->validator->validateDirectory($stagingPath);
            $deletion = new TranslationFileDeletion($this->files->forPath($stagingPath), app(TranslationFileWriter::class), $this->validator);
            $deletedFiles = $deletion->prepare($pending, false);
            foreach ($deletedFiles as $path => $change) {
                File::replace($path, $change['after']);
            }
            $publishedFiles = [];

            foreach (array_unique([...$stagedResult->files(), ...array_keys($deletedFiles)]) as $stagedFile) {
                $prefix = rtrim($stagingPath, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

                if (! str_starts_with($stagedFile, $prefix)) {
                    throw new RuntimeException('A generated translation file escaped the publishing directory.');
                }

                $relativePath = substr($stagedFile, strlen($prefix));
                $destination = rtrim($langPath, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$relativePath;
                File::ensureDirectoryExists(dirname($destination));

                app(TranslationFileTransaction::class)->replace($destination, File::get($stagedFile));

                $publishedFiles[] = $destination;
            }

            $frontendFiles = config('vox.frontend.runtime.enabled', false)
                ? $this->frontendArtifacts->publish($langPath)
                : [];

            $runtimeChanges = app(TranslationFileDeletion::class)->prepare($pending);
            foreach ($runtimeChanges as $path => $change) {
                app(TranslationFileTransaction::class)->replace($path, $change['after']);
                $frontendFiles[] = $path;
            }

            DB::connection(config('vox.database.connection', 'vox'))->transaction(function () use ($stagedResult, $pending): void {
                foreach ($pending as $row) {
                    DB::connection($row->getConnectionName())->table('vox_remote_translations')->where('group', $row->group ?? 'json')->where('key', $row->key)->delete();
                    $row->occurrences()->delete();
                    $row->values()->delete();
                    $row->delete();
                }
                foreach ($stagedResult->publishedValues() as $id => $value) {
                    $current = VoxTranslationValue::query()->lockForUpdate()->find($id);

                    if ($current !== null && $current->value === $value) {
                        $current->timestamps = false;
                        if ($current->is_pending_publish || $current->value !== $current->file_value) {
                            $current->published_override = $value;
                        }
                        $current->is_pending_publish = false;
                        $current->save();
                    }
                }
            });

            $result = new PublishResult(
                $stagedResult->values(),
                $publishedFiles,
                $stagedResult->incompleteTranslations(),
                $stagedResult->orphanTranslations(),
                $frontendFiles,
                deletedKeys: $pending->map(fn ($row): array => ['group' => $row->group, 'key' => $row->key])->all(),
            );

            $event = new TranslationsPublished(
                config('vox.frontend.runtime.enabled', false) ? 'runtime' : 'bundled',
                $result,
                $regenerate,
            );
            DB::connection(config('vox.database.connection', 'vox'))->afterCommit(
                fn () => app(TranslationFileTransaction::class)->afterCommit(fn () => event($event))
            );

            return $result;
        } finally {
            File::deleteDirectory($stagingPath);
        }
    }

    public function publishTo(string $langPath, ?array $valueIds = null, bool $overridesOnly = false): PublishResult
    {
        $this->validator->validateDirectory($langPath);
        $result = $this->publishUsing($this->files->forPath($langPath), $valueIds, $overridesOnly);
        $this->validator->validateDirectory($langPath);

        return $result;
    }

    private function publishUsing(TranslationFileRepository $files, ?array $valueIds, bool $overridesOnly, bool $includeDefaults = false): PublishResult
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
            ->where('is_ignored', false)
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
            $incomplete = false;

            foreach ($values as $locale => $translationValue) {
                if (! in_array($locale, $locales, true) || ($valueIds !== null && ! in_array($translationValue->id, $valueIds, true))) {
                    continue;
                }
                $publishDraft = ! $overridesOnly && ! $translationValue->is_obsolete
                    && $translationValue->is_approved
                    && ($translationValue->is_pending_publish || $translationValue->file_value === null);
                if ($publishDraft && (! is_string($translationValue->value)
                    || $translationValue->value === '' || Str::startsWith($translationValue->value, $prefix))) {
                    $incomplete = true;
                    $publishDraft = false;
                }

                if ($publishDraft) {
                    $value = $translationValue->value;
                    $publishedValues[$translationValue->id] = $value;
                } elseif ($overridesOnly || $includeDefaults) {
                    $value = $translationValue->published_override ?? ($includeDefaults ? $translationValue->file_value : null);
                    if ($value === null) {
                        continue;
                    }
                    if (! is_string($value) || (! $includeDefaults && ($value === '' || Str::startsWith($value, $prefix)))) {
                        $incomplete = true;

                        continue;
                    }
                } else {
                    continue;
                }

                if ($translation->group === null || $translation->group === 'json') {
                    [$namespace, $key] = $this->splitJsonKey($translation->key);
                    $jsonUpdates[$locale][$namespace ?? ''][$key] = $value;
                } else {
                    $groupUpdates[$locale][$translation->group][$translation->key] = $value;
                }
            }
            if ($incomplete) {
                $incompleteTranslations++;
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
