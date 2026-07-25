<?php

namespace KeypointSolutions\LaravelVox\Translation;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use KeypointSolutions\LaravelVox\Models\VoxTranslation;
use KeypointSolutions\LaravelVox\Models\VoxTranslationOccurrence;
use KeypointSolutions\LaravelVox\Models\VoxTranslationValue;
use KeypointSolutions\LaravelVox\Support\VoxDynamicKeyRegistry;

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
    public function sync(array $locales, array $scanResults): SyncResult
    {
        $langPath = $this->files->langPath();
        $result = new SyncResult;

        $groupFiles = $this->collectGroupFiles($langPath, $locales);
        $jsonFiles = $this->collectJsonFiles($langPath, $locales);

        $translations = $this->loadTranslations($groupFiles, $jsonFiles);
        $translations = $this->applyScanMetadata($translations, $scanResults);
        $translations = $this->applyDynamicMetadata($translations);
        $seenTranslationIds = [];

        foreach ($translations as $fullKey => $payload) {
            $translation = VoxTranslation::query()->firstOrNew([
                'key' => $payload['key'],
                'group' => $payload['group'],
            ]);
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
                $translationValue = VoxTranslationValue::query()->firstOrNew(
                    [
                        'translation_id' => $translation->id,
                        'locale' => $locale,
                    ]
                );

                if (
                    ! $translationValue->exists
                    || $translationValue->value !== $value
                    || $translationValue->is_obsolete
                ) {
                    $contentChanged = true;
                }

                $translationValue->fill([
                    'value' => $value,
                    'is_obsolete' => false,
                ])->save();
            }

            if (! $isNew && $contentChanged) {
                $result->incrementChangedTranslations();

                if ($translation->status === 'approved') {
                    $translation->status = 'pending';
                    $result->incrementReopenedTranslations();
                }

                $translation->touch();
            }

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

            $seenTranslationIds[] = $translation->id;
            $result->incrementTranslations();
        }

        $orphanQuery = VoxTranslation::query();

        if ($seenTranslationIds !== []) {
            $orphanQuery->whereNotIn('id', $seenTranslationIds);
        }

        $orphanIds = $orphanQuery
            ->get()
            ->filter(function (VoxTranslation $translation): bool {
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
            })
            ->pluck('id');

        if ($orphanIds->isNotEmpty()) {
            VoxTranslation::query()
                ->whereIn('id', $orphanIds)
                ->toBase()
                ->update([
                    'is_frontend' => false,
                    'is_orphan' => true,
                    'source' => null,
                ]);

            VoxTranslationOccurrence::query()
                ->whereIn('translation_id', $orphanIds)
                ->delete();
        }

        $result->setOrphanTranslations($orphanIds->count());

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

        foreach ($locales as $locale) {
            $groupPath = $langPath.DIRECTORY_SEPARATOR.$locale;

            if (! File::isDirectory($groupPath)) {
                continue;
            }

            foreach (File::files($groupPath) as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $files[] = [
                    'locale' => $locale,
                    'group' => $file->getBasename('.php'),
                    'path' => $file->getPathname(),
                ];
            }
        }

        $vendorRoot = $langPath.DIRECTORY_SEPARATOR.'vendor';

        if (! File::isDirectory($vendorRoot)) {
            return $files;
        }

        foreach (File::directories($vendorRoot) as $vendorPath) {
            $namespace = basename($vendorPath);

            foreach ($locales as $locale) {
                $groupPath = $vendorPath.DIRECTORY_SEPARATOR.$locale;

                if (! File::isDirectory($groupPath)) {
                    continue;
                }

                foreach (File::files($groupPath) as $file) {
                    if ($file->getExtension() !== 'php') {
                        continue;
                    }

                    $files[] = [
                        'locale' => $locale,
                        'group' => $namespace.'::'.$file->getBasename('.php'),
                        'path' => $file->getPathname(),
                    ];
                }
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

        foreach ($locales as $locale) {
            $path = $langPath.DIRECTORY_SEPARATOR.$locale.'.json';

            if (File::exists($path)) {
                $files[] = [
                    'locale' => $locale,
                    'path' => $path,
                    'namespace' => null,
                ];
            }
        }

        $vendorRoot = $langPath.DIRECTORY_SEPARATOR.'vendor';

        if (! File::isDirectory($vendorRoot)) {
            return $files;
        }

        foreach (File::directories($vendorRoot) as $vendorPath) {
            $namespace = basename($vendorPath);

            foreach ($locales as $locale) {
                $path = $vendorPath.DIRECTORY_SEPARATOR.$locale.'.json';

                if (! File::exists($path)) {
                    continue;
                }

                $files[] = [
                    'locale' => $locale,
                    'path' => $path,
                    'namespace' => $namespace,
                ];
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
