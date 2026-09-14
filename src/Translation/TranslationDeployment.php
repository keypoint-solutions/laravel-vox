<?php

namespace KeypointSolutions\LaravelVox\Translation;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use KeypointSolutions\LaravelVox\Support\VoxAuditLogger;
use KeypointSolutions\LaravelVox\Support\VoxMutationLock;
use RuntimeException;

class TranslationDeployment
{
    public function __construct(
        private TranslationFileRepository $files,
        private TranslationFileValidator $validator,
        private TranslationDatabaseSynchronizer $synchronizer,
        private TranslationPublisher $publisher,
        private FrontendTranslationArtifacts $frontend,
        private TranslationFileTransaction $fileTransaction,
        private VoxMutationLock $lock,
        private VoxAuditLogger $audit,
    ) {}

    public function deploy(): SyncResult
    {
        return $this->lock->run(function (): SyncResult {
            $source = $this->files->langPath();
            $this->validator->validateDirectory($source);
            $staging = storage_path('vox/deploy-'.Str::uuid());

            try {
                return $this->fileTransaction->run(fn (): SyncResult => DB::connection(config('vox.database.connection', 'vox'))
                    ->transaction(function () use ($source, $staging): SyncResult {
                        File::ensureDirectoryExists($staging);
                        if (File::isDirectory($source) && ! File::copyDirectory($source, $staging)) {
                            throw new RuntimeException('Unable to stage deployment translations.');
                        }

                        $result = $this->synchronizer->sync(deployment: true, sourceFiles: $this->files->forPath($source));
                        $this->publisher->publishTo($staging, overridesOnly: true);
                        $this->validator->validateDirectory($staging);

                        foreach (File::isDirectory($this->files->langPath()) ? File::allFiles($this->files->langPath()) : [] as $existing) {
                            if (in_array($existing->getExtension(), ['php', 'json'], true)
                                && ! File::exists($staging.DIRECTORY_SEPARATOR.$existing->getRelativePathname())) {
                                $this->fileTransaction->delete($existing->getPathname());
                            }
                        }
                        foreach (File::allFiles($staging) as $file) {
                            $destination = $this->files->langPath().DIRECTORY_SEPARATOR.$file->getRelativePathname();
                            $this->fileTransaction->replace($destination, File::get($file->getPathname()));
                        }
                        if (config('vox.frontend.runtime.enabled', false)) {
                            $this->frontend->publish();
                        }

                        $this->audit->record('deploy', ['translations' => $result->translations()]);

                        return $result;
                    }));
            } finally {
                File::deleteDirectory($staging);
            }
        });
    }
}
