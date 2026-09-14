<?php

namespace KeypointSolutions\LaravelVox\Commands;

use Illuminate\Console\Command;
use KeypointSolutions\LaravelVox\Support\VoxMutationLock;
use KeypointSolutions\LaravelVox\Translation\FrontendTranslationArtifacts;
use KeypointSolutions\LaravelVox\Translation\TranslationFileTransaction;
use Throwable;

class CompileCommand extends Command
{
    public $signature = 'vox:compile';

    public $description = 'Compile frontend translations from current language files without importing or publishing database values.';

    public function handle(FrontendTranslationArtifacts $artifacts, VoxMutationLock $lock, TranslationFileTransaction $files): int
    {
        try {
            $compiled = $lock->run(fn (): array => $files->run(fn (): array => $artifacts->publish()));
            $this->info('Compiled '.count($compiled).' frontend locale files.');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
