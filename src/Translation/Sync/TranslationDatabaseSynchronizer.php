<?php

namespace KeypointSolutions\LaravelVox\Translation\Sync;

use KeypointSolutions\LaravelVox\Support\VoxConfig;
use KeypointSolutions\LaravelVox\Support\VoxMutationLock;
use KeypointSolutions\LaravelVox\Translation\Files\TranslationFileRepository;
use KeypointSolutions\LaravelVox\Translation\Files\TranslationFileTransaction;
use KeypointSolutions\LaravelVox\Translation\Files\TranslationFileUpdater;
use KeypointSolutions\LaravelVox\Translation\Publishing\FrontendTranslationArtifacts;
use KeypointSolutions\LaravelVox\Translation\Publishing\TranslationPublisher;
use KeypointSolutions\LaravelVox\Translation\Publishing\VoxFrontendManifest;
use KeypointSolutions\LaravelVox\Translation\Scanning\TranslationScanPreparation;
use KeypointSolutions\LaravelVox\Translation\Scanning\VoxDynamicKeyRegistry;

class TranslationDatabaseSynchronizer
{
    public function __construct(
        private TranslationFileRepository $files,
        private TranslationScanPreparation $preparation,
        private VoxFrontendManifest $frontendManifest,
        private VoxDynamicKeyRegistry $dynamicKeys,
        private FrontendTranslationArtifacts $frontendArtifacts,
    ) {}

    public function sync(bool $updateLanguageFiles = false, bool $deployment = false, ?TranslationFileRepository $sourceFiles = null): SyncResult
    {
        return app(VoxMutationLock::class)->run(
            fn (): SyncResult => app(TranslationFileTransaction::class)->run(
                fn (): SyncResult => VoxConfig::connection()
                    ->transaction(fn (): SyncResult => $this->syncUsing($updateLanguageFiles, $deployment, $sourceFiles))
            )
        );
    }

    private function syncUsing(bool $updateLanguageFiles, bool $deployment, ?TranslationFileRepository $sourceFiles): SyncResult
    {
        $scan = $this->preparation->prepare();
        $scanResults = $scan['results'];
        $locales = $scan['locales'];
        $baseLocale = $scan['base_locale'];

        $languageFileResult = null;

        if ($updateLanguageFiles) {
            $languageFileResult = (new TranslationFileUpdater($this->files, $this->dynamicKeys))
                ->updateFromScan($scanResults, $locales, $baseLocale);
        }

        $result = (new TranslationSyncer($sourceFiles ?? $this->files, $this->dynamicKeys))->sync($locales, $scanResults, $deployment);

        if ($languageFileResult !== null) {
            $result->setLanguageFileChanges(
                $languageFileResult->added(),
                $languageFileResult->removed()
            );
        }

        $this->frontendManifest->writeFromDatabase();

        if (! $deployment) {
            app(TranslationPublisher::class)->publishTo($this->files->langPath(), overridesOnly: true);
        }

        if (! $deployment && config('vox.frontend.runtime.enabled', false)) {
            $this->frontendArtifacts->publish();
        }

        return $result;
    }
}
