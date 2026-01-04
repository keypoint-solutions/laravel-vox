<?php

namespace KeypointSolutions\LaravelVox\Translation;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use KeypointSolutions\LaravelVox\Models\VoxTranslation;
use KeypointSolutions\LaravelVox\Models\VoxTranslationOccurrence;
use KeypointSolutions\LaravelVox\Models\VoxTranslationValue;

class TranslationSyncer
{
    public function __construct(private TranslationFileRepository $files)
    {
    }

    /**
     * @param array<int, string> $locales
     * @param array<string, array<string, mixed>> $scanResults
     */
    public function sync(array $locales, array $scanResults): SyncResult
    {
        $langPath = $this->files->langPath();
        $result = new SyncResult();

        $groupFiles = $this->collectGroupFiles($langPath, $locales);
        $jsonFiles = $this->collectJsonFiles($langPath, $locales);

        $translations = $this->loadTranslations($groupFiles, $jsonFiles);
        $translations = $this->applyScanMetadata($translations, $scanResults);

        foreach ($translations as $fullKey => $payload) {
            $translation = VoxTranslation::query()->firstOrCreate(
                ['key' => $payload['key'], 'group' => $payload['group']],
                [
                    'is_frontend' => $payload['is_frontend'],
                    'source' => $payload['source'],
                    'status' => 'pending',
                ]
            );

            if ($payload['is_frontend'] && ! $translation->is_frontend) {
                $translation->is_frontend = true;
                $translation->save();
            }

            if ($payload['source'] !== null && $translation->source === null) {
                $translation->source = $payload['source'];
                $translation->save();
            }

            foreach ($payload['values'] as $locale => $value) {
                VoxTranslationValue::query()->updateOrCreate(
                    [
                        'translation_id' => $translation->id,
                        'locale' => $locale,
                    ],
                    [
                        'value' => $value,
                        'is_obsolete' => false,
                    ]
                );
            }

            if ($payload['occurrences'] !== []) {
                VoxTranslationOccurrence::query()
                    ->where('translation_id', $translation->id)
                    ->delete();

                foreach ($payload['occurrences'] as $occurrence) {
                    VoxTranslationOccurrence::query()->create([
                        'translation_id' => $translation->id,
                        'file_path' => $occurrence['file'],
                        'line_number' => $occurrence['line'],
                        'context_before' => $occurrence['before'],
                        'context_after' => $occurrence['after'],
                    ]);
                }
            }

            $result->incrementTranslations();
        }

        return $result;
    }

    /**
     * @param array<int, string> $locales
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
     * @param array<int, string> $locales
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
     * @param array<int, array{locale: string, group: string, path: string}> $groupFiles
     * @param array<int, array{locale: string, path: string, namespace: string|null}> $jsonFiles
     * @return array<string, array{key: string, group: string|null, values: array<string, string>, is_frontend: bool, source: string|null, occurrences: array<int, array<string, mixed>>}>
     */
    private function loadTranslations(array $groupFiles, array $jsonFiles): array
    {
        $translations = [];

        foreach ($groupFiles as $file) {
            $entries = $this->files->loadGroup($file['locale'], $file['group']);
            $flat = Arr::dot($entries);

            foreach ($flat as $key => $value) {
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
                $fullKey = $namespace !== null ? $namespace.'::'.$key : $key;
                $translations[$fullKey]['group'] = null;
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
     * @param array<string, array<string, mixed>> $translations
     * @param array<string, array<string, mixed>> $scanResults
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
