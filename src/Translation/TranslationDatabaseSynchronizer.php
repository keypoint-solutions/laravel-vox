<?php

namespace KeypointSolutions\LaravelVox\Translation;

use KeypointSolutions\LaravelVox\Support\VoxFrontendManifest;
use KeypointSolutions\LaravelVox\Support\VoxLocaleResolver;

class TranslationDatabaseSynchronizer
{
    public function __construct(
        private TranslationFileRepository $files,
        private VoxLocaleResolver $localeResolver,
        private VoxFrontendManifest $frontendManifest,
    ) {}

    public function sync(): SyncResult
    {
        $scanner = new TranslationScanner(
            base_path(),
            config('vox.parse.paths', []),
            config('vox.parse.exclude', []),
            config('vox.parse.extensions', []),
            (int) config('vox.parse.context_lines', 3)
        );
        $scanResults = $scanner->scan();
        $locales = $this->localeResolver->resolveLocales();
        $baseLocale = $this->localeResolver->resolveBaseLocale($locales);

        if (! in_array($baseLocale, $locales, true)) {
            $locales[] = $baseLocale;
        }

        $result = (new TranslationSyncer($this->files))->sync($locales, $scanResults);
        $this->frontendManifest->writeFromDatabase();

        return $result;
    }
}
