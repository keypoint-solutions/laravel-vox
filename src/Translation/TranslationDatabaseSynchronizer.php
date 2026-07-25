<?php

namespace KeypointSolutions\LaravelVox\Translation;

use KeypointSolutions\LaravelVox\Support\VoxDynamicKeyRegistry;
use KeypointSolutions\LaravelVox\Support\VoxFrontendManifest;
use KeypointSolutions\LaravelVox\Support\VoxLocaleResolver;

class TranslationDatabaseSynchronizer
{
    public function __construct(
        private TranslationFileRepository $files,
        private VoxLocaleResolver $localeResolver,
        private VoxFrontendManifest $frontendManifest,
        private VoxDynamicKeyRegistry $dynamicKeys,
        private FrontendTranslationArtifacts $frontendArtifacts,
    ) {}

    public function sync(bool $updateLanguageFiles = false): SyncResult
    {
        $scanner = new TranslationScanner(
            base_path(),
            config('vox.parse.paths', []),
            config('vox.parse.exclude', []),
            config('vox.parse.extensions', []),
            (int) config('vox.parse.context_lines', 3)
        );
        $scanResults = $scanner->scan();
        $this->dynamicKeys->writeDetectedPatterns($scanner->dynamicKeys());
        $scanResults = $this->dynamicKeys->mergeEnumeratedScanResults($scanResults);
        $locales = $this->localeResolver->resolveLocales();
        $baseLocale = $this->localeResolver->resolveBaseLocale($locales);

        if (! in_array($baseLocale, $locales, true)) {
            $locales[] = $baseLocale;
        }

        $languageFileResult = null;

        if ($updateLanguageFiles) {
            $languageFileResult = (new TranslationFileUpdater($this->files, $this->dynamicKeys))
                ->updateFromScan($scanResults, $locales, $baseLocale);
        }

        $result = (new TranslationSyncer($this->files, $this->dynamicKeys))->sync($locales, $scanResults);

        if ($languageFileResult !== null) {
            $result->setLanguageFileChanges(
                $languageFileResult->added(),
                $languageFileResult->removed()
            );
        }

        $this->frontendManifest->writeFromDatabase();

        if (config('vox.frontend.runtime.enabled', false)) {
            $this->frontendArtifacts->publish();
        }

        return $result;
    }
}
