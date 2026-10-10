<?php

namespace KeypointSolutions\LaravelVox\Translation\Sync;

use Illuminate\Support\Arr;
use KeypointSolutions\LaravelVox\Models\VoxRemoteTranslation;
use KeypointSolutions\LaravelVox\Models\VoxTranslation;
use KeypointSolutions\LaravelVox\Models\VoxTranslationOccurrence;
use KeypointSolutions\LaravelVox\Models\VoxTranslationValue;
use KeypointSolutions\LaravelVox\Support\VoxConfig;
use KeypointSolutions\LaravelVox\Support\VoxMutationLock;
use KeypointSolutions\LaravelVox\Translation\Files\TranslationFileRepository;
use KeypointSolutions\LaravelVox\Translation\Remote\RemoteReconciliation;
use KeypointSolutions\LaravelVox\Translation\Remote\RemoteTranslationSnapshot;
use KeypointSolutions\LaravelVox\Translation\Scanning\VoxDynamicKeyRegistry;
use KeypointSolutions\LaravelVox\Translation\TranslationFallbackRules;

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
            fn (): SyncResult => VoxConfig::connection()
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
        $rules = app(TranslationFallbackRules::class);
        $rules->importManifest($langPath);

        $groupFiles = $this->collectGroupFiles($langPath, $locales);
        $jsonFiles = $this->collectJsonFiles($langPath, $locales);

        $translations = $this->loadTranslations($groupFiles, $jsonFiles);
        $translations = $this->applyScanMetadata($translations, $scanResults);
        $translations = $this->applyDynamicMetadata($translations);
        $seenTranslationIds = [];
        $seenValueIds = [];
        $fileCandidates = $deployment
            ? collect()
            : VoxRemoteTranslation::query()->whereNull('environment_id')->pluck('identity')->flip();

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
                if ($rules->usesDefault($locale, $translation->group, $translation->key, true)) {
                    if (! $translationValue->exists) {
                        $translationValue->fill(['value' => '', 'is_approved' => true, 'is_pending_publish' => false, 'is_obsolete' => false])->save();
                    }
                    $seenValueIds[$translationValue->id] = true;

                    continue;
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

            $this->syncOccurrences($translation, $payload['occurrences'] ?? []);

            $seenTranslationIds[$translation->id] = true;
            $result->incrementTranslations();
        }

        if ($deployment) {
            $lastValueId = 0;
            do {
                $valueIds = VoxTranslationValue::query()
                    ->whereHas('translation', fn ($query) => $query->where('is_pending_delete', false))
                    ->where('id', '>', $lastValueId)
                    ->orderBy('id')
                    ->limit(200)
                    ->toBase()
                    ->pluck('id')
                    ->all();

                if ($valueIds === []) {
                    break;
                }

                $lastValueId = $valueIds[array_key_last($valueIds)];
                $absentIds = array_values(array_filter($valueIds, fn (int $id): bool => ! isset($seenValueIds[$id])));

                if ($absentIds !== []) {
                    foreach (VoxTranslationValue::query()->whereKey($absentIds)->lockForUpdate()->get() as $value) {
                        $value->file_value = null;
                        $value->is_obsolete = $value->published_override === null && ! $value->is_pending_publish;
                        $value->save();
                    }
                }
            } while (count($valueIds) === 200);
        }

        $orphanCount = 0;
        $lastTranslationId = 0;
        do {
            $translationIds = VoxTranslation::query()
                ->where('is_pending_delete', false)
                ->where('id', '>', $lastTranslationId)
                ->orderBy('id')
                ->limit(200)
                ->toBase()
                ->pluck('id')
                ->all();

            if ($translationIds === []) {
                break;
            }

            $lastTranslationId = $translationIds[array_key_last($translationIds)];
            $candidateIds = array_values(array_filter($translationIds, fn (int $id): bool => ! isset($seenTranslationIds[$id])));

            if ($candidateIds !== []) {
                $candidates = VoxTranslation::query()
                    ->whereKey($candidateIds)
                    ->with('values')
                    ->lockForUpdate()
                    ->get();

                foreach ($candidates as $translation) {
                    if (! $translation->is_orphan && $translation->values->contains(
                        fn ($value): bool => $value->is_pending_publish && $value->file_value === null && $value->published_override === null
                    )) {
                        continue;
                    }

                    if ($deployment && ! $translation->is_orphan && ($translation->values->contains('is_pending_publish', true) || $translation->values->contains(fn ($value): bool => $value->published_override !== null))) {
                        continue;
                    }

                    $match = $this->dynamicKeys->match(
                        $translation->key,
                        $translation->group === 'json' ? null : $translation->group
                    );

                    if ($match !== null) {
                        $translation->timestamps = false;
                        $translation->is_frontend = $match['is_frontend'];
                        $translation->is_orphan = false;
                        $translation->source = 'dynamic';
                        $translation->save();

                        continue;
                    }

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
            }
        } while (count($translationIds) === 200);

        $result->setOrphanTranslations($orphanCount);

        return $result;
    }

    /**
     * @param  array<int, array{file: string, line: int|null, before: string|null, after: string|null}>  $occurrences
     */
    private function syncOccurrences(VoxTranslation $translation, array $occurrences): void
    {
        $existing = VoxTranslationOccurrence::query()
            ->where('translation_id', $translation->id)
            ->toBase()
            ->get(['file_path', 'line_number', 'context_before', 'context_after'])
            ->map(fn (object $occurrence): array => [
                'file_path' => $occurrence->file_path,
                'line_number' => $occurrence->line_number === null ? null : (int) $occurrence->line_number,
                'context_before' => $occurrence->context_before,
                'context_after' => $occurrence->context_after,
            ])->all();

        $desired = array_map(static fn (array $occurrence): array => [
            'file_path' => $occurrence['file'],
            'line_number' => $occurrence['line'],
            'context_before' => $occurrence['before'],
            'context_after' => $occurrence['after'],
        ], $occurrences);

        $existingContent = array_map(serialize(...), $existing);
        $desiredContent = array_map(serialize(...), $desired);
        sort($existingContent);
        sort($desiredContent);

        if ($existingContent === $desiredContent) {
            return;
        }

        VoxTranslationOccurrence::query()->where('translation_id', $translation->id)->delete();

        if ($desired === []) {
            return;
        }

        $timestamp = now()->toDateTimeString();
        foreach (array_chunk($desired, 50) as $chunk) {
            VoxTranslationOccurrence::query()->insert(array_map(static fn (array $occurrence): array => [
                ...$occurrence,
                'translation_id' => $translation->id,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ], $chunk));
        }
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
