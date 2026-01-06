<?php

namespace KeypointSolutions\LaravelVox\Commands;

use Illuminate\Console\Command;
use KeypointSolutions\LaravelVox\Support\VoxAuditLogger;
use KeypointSolutions\LaravelVox\Support\VoxLocaleResolver;
use KeypointSolutions\LaravelVox\Translation\TranslationFileRepository;
use KeypointSolutions\LaravelVox\Translation\TranslationFileWriter;
use KeypointSolutions\LaravelVox\Translation\TranslationScanner;
use KeypointSolutions\LaravelVox\Translation\TranslationSyncer;

use function Laravel\Prompts\info;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\table;

class SyncTranslationsCommand extends Command
{
    public $signature = 'vox:sync';

    public $description = 'Sync translation files into the Vox database.';

    public function handle(): int
    {
        $scanner = new TranslationScanner(
            base_path(),
            config('vox.parse.paths', []),
            config('vox.parse.exclude', []),
            config('vox.parse.extensions', []),
            (int) config('vox.parse.context_lines', 3)
        );

        $scanResults = spin(fn () => $scanner->scan(), 'Scanning translation keys');

        $localeResolver = new VoxLocaleResolver;
        $locales = $localeResolver->resolveLocales();
        $baseLocale = $localeResolver->resolveBaseLocale($locales);

        if (! in_array($baseLocale, $locales, true)) {
            $locales[] = $baseLocale;
        }

        $fileRepository = new TranslationFileRepository(new TranslationFileWriter);
        $syncer = new TranslationSyncer($fileRepository);

        $result = $syncer->sync($locales, $scanResults);

        app(VoxAuditLogger::class)->record('sync', [
            'translations' => $result->translations(),
        ]);

        info('Database synced.');
        table(['Metric', 'Count'], [
            ['Translations', (string) $result->translations()],
        ]);

        return self::SUCCESS;
    }
}
