<?php

namespace KeypointSolutions\LaravelVox\Translation\Scanning;

use KeypointSolutions\LaravelVox\Translation\Locales\VoxLocaleResolver;

class TranslationScanPreparation
{
    public function __construct(
        private VoxDynamicKeyRegistry $dynamicKeys,
        private VoxLocaleResolver $localeResolver,
    ) {}

    /**
     * @return array{results: array<string, array<string, mixed>>, dynamic_keys: array<int, array<string, mixed>>, files: array<int, string>, locales: array<int, string>, base_locale: string}
     */
    public function prepare(): array
    {
        $scanner = new TranslationScanner(
            base_path(),
            config('vox.parse.paths', []),
            config('vox.parse.exclude', []),
            config('vox.parse.extensions', []),
            (int) config('vox.parse.context_lines', 3)
        );
        $results = $scanner->scan();
        $dynamicKeys = $scanner->dynamicKeys();
        $this->dynamicKeys->writeDetectedPatterns($dynamicKeys);
        $results = $this->dynamicKeys->mergeEnumeratedScanResults($results);
        [$locales, $baseLocale] = $this->localeResolver->resolveLocalesWithBase();

        return [
            'results' => $results,
            'dynamic_keys' => $dynamicKeys,
            'files' => $scanner->files(),
            'locales' => $locales,
            'base_locale' => $baseLocale,
        ];
    }
}
