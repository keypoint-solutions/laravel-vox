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
    public $signature = 'vox:sync
        {--parse : Update language files from discovered source keys before syncing}';

    public $description = 'Sync translation files into the Vox database.';

    public function handle(): int
    {
        $startedAt = now();
        $result = spin(
            fn () => app(TranslationDatabaseSynchronizer::class)->sync((bool) $this->option('parse')),
            'Scanning translation keys and syncing the database'
        );

        app(VoxAuditLogger::class)->record('sync', [
            'started_at' => $startedAt->toIso8601String(),
            'translations' => $result->translations(),
            'changed_translations' => $result->changedTranslations(),
            'reopened_translations' => $result->reopenedTranslations(),
            'orphan_translations' => $result->orphanTranslations(),
            'added_language_keys' => $result->addedLanguageKeys(),
            'removed_language_keys' => $result->removedLanguageKeys(),
        ]);

        info('Database synced.');
        table(['Metric', 'Count'], [
            ['Translations', (string) $result->translations()],
            ['Orphans', (string) $result->orphanTranslations()],
            ['Language keys added', (string) $result->addedLanguageKeys()],
            ['Language keys removed', (string) $result->removedLanguageKeys()],
        ]);

        return self::SUCCESS;
    }
}
