<?php

namespace KeypointSolutions\LaravelVox\Translation;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use KeypointSolutions\LaravelVox\Models\VoxRemoteTranslation;
use KeypointSolutions\LaravelVox\Models\VoxTranslation;
use KeypointSolutions\LaravelVox\Models\VoxTranslationOccurrence;
use KeypointSolutions\LaravelVox\Models\VoxTranslationValue;
use KeypointSolutions\LaravelVox\Support\VoxDynamicKeyRegistry;
use KeypointSolutions\LaravelVox\Support\VoxMutationLock;

class TranslationSyncer
{
    public function __construct(
        private TranslationFileRepository $files,
        private VoxDynamicKeyRegistry $dynamicKeys,
    ) {}

    /**
     * @param  array<int, string>  $locales
     * @param  array<string, array<string, mixed>>  $scanResults
     */
    public function sync(array $locales, array $scanResults, bool $deployment = false): SyncResult
    {
        return app(VoxMutationLock::class)->run(
            fn (): SyncResult => DB::connection(config('vox.database.connection', 'vox'))
                ->transaction(fn (): SyncResult => $this->syncTranslations($locales, $scanResults, $deployment)),
        );
    }

    /**
     * @param  array<int, string>  $locales
     * @param  array<string, array<string, mixed>>  $scanResults
     */
    private function syncTranslations(array $locales, array $scanResults, bool $deployment): SyncResult
    {
        $langPath = $this->files->langPath();
        $result = new SyncResult;

        $groupFiles = $this->collectGroupFiles($langPath, $locales);
        $jsonFiles = $this->collectJsonFiles($langPath, $locales);

        $translations = $this->loadTranslations($groupFiles, $jsonFiles);
        $translations = $this->applyScanMetadata($translations, $scanResults);
        $translations = $this->applyDynamicMetadata($translations);
        $seenTranslationIds = [];
        $seenValueIds = [];
        $fileCandidates = VoxRemoteTranslation::query()->whereNull('environment_id')->pluck('identity')->flip();

        foreach ($translations as $fullKey => $payload) {
            $translation = VoxTranslation::query()->lockForUpdate()->firstOrNew([
                'key' => $payload['key'],
                'group' => $payload['group'],
            ]);
            if ($translation->exists && $translation->is_pending_delete) {
                $seenTranslationIds[$translation->id] = true;

                continue;
            }
            $isNew = ! $translation->exists;

            if ($isNew) {
                $translation->fill([
                    'is_frontend' => $payload['is_frontend'],
                    'is_orphan' => false,
                    'source' => $payload['source'],
                    'status' => 'pending',
                ])->save();
            }

            if ($translation->is_orphan) {
                $translation->timestamps = false;
                $translation->is_orphan = false;
                $translation->save();
                $translation->timestamps = true;
            }

            if ($translation->is_frontend !== $payload['is_frontend']) {
                $translation->timestamps = false;
                $translation->is_frontend = $payload['is_frontend'];
                $translation->save();
                $translation->timestamps = true;
            }

            if ($translation->source !== $payload['source']) {
                $translation->timestamps = false;
                $translation->source = $payload['source'];
                $translation->save();
                $translation->timestamps = true;
            }

            $contentChanged = false;

            foreach ($payload['values'] as $locale => $value) {
                $translationValue = VoxTranslationValue::query()->lockForUpdate()->firstOrNew(
                    [
                        'translation_id' => $translation->id,
                        'locale' => $locale,
                    ]
                );

                if ($translationValue->exists) {
                    $seenValueIds[$translationValue->id] = true;
                }
                $oldFileValue = $translationValue->file_value;
                $current = $translationValue->value;
                $isNewValue = ! $translationValue->exists;

                if (! $deployment && $translationValue->published_override !== null && $value === $translationValue->published_override) {
                    if ($fileCandidates->has(RemoteTranslationSnapshot::identity($translation->group, $translation->key, $locale))) {
                        app(RemoteReconciliation::class)->ingestFileValue($translation, $locale, $value, $oldFileValue ?? $current);
                    }

                    continue;
                }

                $translationValue->file_value = $value;
                $translationValue->is_obsolete = false;

                if ($isNewValue || ($deployment && ! $translationValue->is_pending_publish
                    && $translationValue->published_override === null && ($current === $oldFileValue || $current === $value))) {
                    $translationValue->value = $value;
                    $translationValue->is_approved = true;
                    $translationValue->is_pending_publish = false;
                } elseif ($current === $value && ! $translationValue->is_pending_publish) {
                    $translationValue->is_approved = true;
                }
                if (! $isNewValue && ! $deployment && ($current !== $value || $fileCandidates->has(RemoteTranslationSnapshot::identity($translation->group, $translation->key, $locale)))) {
                    app(RemoteReconciliation::class)->ingestFileValue(
                        $translation, $locale, $value, $oldFileValue ?? $current
                    );
                }

                if (! $isNewValue && $oldFileValue === null && $current !== $value) {
                    $translationValue->is_pending_publish = true;
                }

                $contentChanged = $contentChanged || $oldFileValue !== $value;
                $translationValue->save();
                $seenValueIds[$translationValue->id] = true;
            }

            if (! $isNew && $contentChanged) {
                $result->incrementChangedTranslations();
                $translation->touch();
            }
            $translation->refreshApproval();

            VoxTranslationOccurrence::query()
                ->where('translation_id', $translation->id)
                ->delete();

            foreach ($payload['occurrences'] ?? [] as $occurrence) {
                VoxTranslationOccurrence::query()->create([
                    'translation_id' => $translation->id,
                    'file_path' => $occurrence['file'],
                    'line_number' => $occurrence['line'],
                    'context_before' => $occurrence['before'],
                    'context_after' => $occurrence['after'],
                ]);
            }

            $seenTranslationIds[$translation->id] = true;
            $result->incrementTranslations();
        }

        if ($deployment) {
            $absent = VoxTranslationValue::query()
                ->whereHas('translation', fn ($query) => $query->where('is_pending_delete', false))->lockForUpdate()->lazyById(200);
            foreach ($absent as $value) {
                if (isset($seenValueIds[$value->id])) {
                    continue;
                }

                $value->file_value = null;
                $value->is_obsolete = $value->published_override === null && ! $value->is_pending_publish;
                $value->save();
            }
        }

        $orphanQuery = VoxTranslation::query()->where('is_pending_delete', false);

        $orphanCount = 0;
        $orphans = $orphanQuery
            ->lockForUpdate()
            ->with('values')
            ->lazyById(200)
            ->filter(function (VoxTranslation $translation) use ($deployment, $seenTranslationIds): bool {
                if (isset($seenTranslationIds[$translation->id])) {
                    return false;
                }

                if (! $translation->is_orphan && $translation->values->contains(
                    fn ($value): bool => $value->is_pending_publish && $value->file_value === null && $value->published_override === null
                )) {
                    return false;
                }

                if ($deployment && ! $translation->is_orphan && ($translation->values->contains('is_pending_publish', true) || $translation->values->contains(fn ($value): bool => $value->published_override !== null))) {
                    return false;
                }

                $match = $this->dynamicKeys->match(
                    $translation->key,
                    $translation->group === 'json' ? null : $translation->group
                );

                if ($match === null) {
                    return true;
                }

                $translation->timestamps = false;
                $translation->is_frontend = $match['is_frontend'];
                $translation->is_orphan = false;
                $translation->source = 'dynamic';
                $translation->save();
                $translation->timestamps = true;

                return false;
            });

        foreach ($orphans as $translation) {
            $orphanCount++;
            VoxTranslation::query()
                ->whereKey($translation->id)
                ->toBase()
                ->update([
                    'is_frontend' => false,
                    'is_orphan' => true,
                    'source' => null,
                ]);

            VoxTranslationOccurrence::query()
                ->where('translation_id', $translation->id)
                ->delete();
        }

        $result->setOrphanTranslations($orphanCount);

        return $result;
    }

    /**
     * @param  array<string, array<string, mixed>>  $translations
     * @return array<string, array<string, mixed>>
     */
    private function applyDynamicMetadata(array $translations): array
    {
        foreach ($translations as $fullKey => $payload) {
            $group = ($payload['group'] ?? null) === 'json' ? null : ($payload['group'] ?? null);
            $key = $payload['key'] ?? null;

            if (! is_string($key)) {
                continue;
            }

            $match = $this->dynamicKeys->match($key, is_string($group) ? $group : null);

            if ($match === null) {
                continue;
            }

            $translations[$fullKey]['is_frontend'] = ($payload['is_frontend'] ?? false)
                || $match['is_frontend'];
            $translations[$fullKey]['source'] = $payload['source'] ?? 'dynamic';

            if (($translations[$fullKey]['occurrences'] ?? []) === []) {
                $translations[$fullKey]['occurrences'] = array_map(
                    static fn (array $occurrence): array => [
                        'file' => $occurrence['file'],
                        'line' => $occurrence['line'],
                        'before' => '',
                        'after' => $occurrence['context'] ?? '',
                    ],
                    $match['occurrences']
                );
            }
        }

        return $translations;
    }

    /**
     * @param  array<int, string>  $locales
     * @return array<int, array{locale: string, group: string, path: string}>
     */
    private function collectGroupFiles(string $langPath, array $locales): array
    {
        $files = [];
        $repository = $this->files->forPath($langPath);
        foreach ($locales as $locale) {
            foreach ($repository->groups($locale) as $group) {
                $files[] = ['locale' => $locale, 'group' => $group, 'path' => $repository->groupPath($locale, $group)];
            }
        }

        return $files;
    }

    /**
     * @param  array<int, string>  $locales
     * @return array<int, array{locale: string, path: string, namespace: string|null}>
     */
    private function collectJsonFiles(string $langPath, array $locales): array
    {
        $files = [];
        $repository = $this->files->forPath($langPath);
        foreach ($locales as $locale) {
            foreach ($repository->jsonNamespaces($locale) as $namespace) {
                $files[] = ['locale' => $locale, 'namespace' => $namespace, 'path' => $repository->jsonPath($locale, $namespace)];
            }
        }

        return $files;
    }

    /**
     * @param  array<int, array{locale: string, group: string, path: string}>  $groupFiles
     * @param  array<int, array{locale: string, path: string, namespace: string|null}>  $jsonFiles
     * @return array<string, array{key: string, group: string|null, values: array<string, string>, is_frontend: bool, source: string|null, occurrences: array<int, array<string, mixed>>}>
     */
    private function loadTranslations(array $groupFiles, array $jsonFiles): array
    {
        $translations = [];

        foreach ($groupFiles as $file) {
            $entries = $this->files->loadGroup($file['locale'], $file['group']);
            $flat = Arr::dot($entries);

            foreach ($flat as $key => $value) {
                if (! is_string($value)) {
                    continue;
                }

                $fullKey = $file['group'].'.'.$key;
                $translations[$fullKey]['group'] = $file['group'];
                $translations[$fullKey]['key'] = $key;
                $translations[$fullKey]['values'][$file['locale']] = (string) $value;
            }
        }

        foreach ($jsonFiles as $file) {
            $namespace = $file['namespace'];
            $entries = $this->files->loadJson($file['locale'], $namespace);

            foreach ($entries as $key => $value) {
                if (! is_string($value)) {
                    continue;
                }

                $fullKey = $namespace !== null ? $namespace.'::'.$key : $key;
                $translations[$fullKey]['group'] = 'json';
                $translations[$fullKey]['key'] = $namespace !== null ? $namespace.'::'.$key : $key;
                $translations[$fullKey]['values'][$file['locale']] = (string) $value;
            }
        }

        foreach ($translations as $fullKey => $payload) {
            $translations[$fullKey]['is_frontend'] = false;
            $translations[$fullKey]['source'] = null;
            $translations[$fullKey]['occurrences'] = [];
        }

        return $translations;
    }

    /**
     * @param  array<string, array<string, mixed>>  $translations
     * @param  array<string, array<string, mixed>>  $scanResults
     * @return array<string, array<string, mixed>>
     */
    private function applyScanMetadata(array $translations, array $scanResults): array
    {
        foreach ($scanResults as $fullKey => $entry) {
            if (! isset($translations[$fullKey])) {
                $translations[$fullKey] = [
                    'group' => $entry['group'],
                    'key' => $entry['key'],
                    'values' => [],
                ];
            }

            $translations[$fullKey]['is_frontend'] = $entry['is_frontend'] ?? false;
            $translations[$fullKey]['source'] = $entry['source'] ?? null;
            $translations[$fullKey]['occurrences'] = $entry['occurrences'] ?? [];
        }

        return $translations;
    }
}
