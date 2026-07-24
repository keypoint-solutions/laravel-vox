<?php

namespace KeypointSolutions\LaravelVox\Commands;

use Illuminate\Console\Command;
use KeypointSolutions\LaravelVox\Support\VoxAuditLogger;
use KeypointSolutions\LaravelVox\Translation\TranslationDatabaseSynchronizer;

use function Laravel\Prompts\info;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\table;

class SyncTranslationsCommand extends Command
{
    public $signature = 'vox:sync';

    public $description = 'Sync translation files into the Vox database.';

    public function handle(): int
    {
        $startedAt = now();
        $result = spin(
            fn () => app(TranslationDatabaseSynchronizer::class)->sync(),
            'Scanning translation keys and syncing the database'
        );

        app(VoxAuditLogger::class)->record('sync', [
            'started_at' => $startedAt->toIso8601String(),
            'translations' => $result->translations(),
            'changed_translations' => $result->changedTranslations(),
            'reopened_translations' => $result->reopenedTranslations(),
        ]);

        info('Database synced.');
        table(['Metric', 'Count'], [
            ['Translations', (string) $result->translations()],
        ]);

        return self::SUCCESS;
    }
}
