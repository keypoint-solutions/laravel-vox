<?php

namespace KeypointSolutions\LaravelVox\Translation;

use Illuminate\Support\Facades\DB;
use KeypointSolutions\LaravelVox\Support\VoxDynamicKeyRegistry;
use KeypointSolutions\LaravelVox\Support\VoxFrontendManifest;
use KeypointSolutions\LaravelVox\Support\VoxMutationLock;

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
                fn (): SyncResult => DB::connection(config('vox.database.connection', 'vox'))
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
