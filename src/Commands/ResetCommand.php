<?php

namespace KeypointSolutions\LaravelVox\Commands;

use Illuminate\Console\Command;
use KeypointSolutions\LaravelVox\Translation\TranslationResetter;

class ResetCommand extends Command
{
    public $signature = 'vox:reset {--scope=translations : translations or all} {--force : Permanently delete data without the typed confirmation}';

    public $description = 'Destructively clear Vox translation data or all Vox data. Published language files are preserved.';

    public function handle(TranslationResetter $resetter): int
    {
        $scope = $this->option('scope');

        if (! in_array($scope, TranslationResetter::SCOPES, true)) {
            $this->error('Scope must be translations or all.');

            return self::FAILURE;
        }

        $this->warn('DESTRUCTIVE ACTION: permanently delete all translation keys, values, source occurrences, and remote reconciliation records.');
        $this->warn($scope === 'all'
            ? 'Saved settings, environments (including their connection credentials), and audit history will also be deleted. A new reset audit event will be recorded.'
            : 'Saved settings, environments, and audit history will be kept. Environment pull status will be cleared.');
        $this->line('Published language files, runtime translation files, application configuration, and application credentials will not be changed.');
        $this->line('Unpublished translations cannot be recovered from language files. Back up the Vox database before continuing.');

        if (! $this->option('force')) {
            $confirmation = TranslationResetter::confirmation($scope);

            if (! $this->input->isInteractive() || $this->ask("Type {$confirmation} to continue") !== $confirmation) {
                $this->warn('Reset cancelled. No data was changed.');

                return self::FAILURE;
            }
        }

        $deleted = $resetter->reset($scope);
        $this->info('Vox reset complete. Deleted '.array_sum($deleted).' records. Published language files were not changed.');

        return self::SUCCESS;
    }
}
