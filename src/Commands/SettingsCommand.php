<?php

namespace KeypointSolutions\LaravelVox\Commands;

use Illuminate\Console\Command;
use KeypointSolutions\LaravelVox\Support\VoxSettingsRepository;

use function Laravel\Prompts\table;

class SettingsCommand extends Command
{
    public $signature = 'vox:settings';

    public $description = 'Display the current Vox configuration.';

    public function handle(): int
    {
        $settings = app(VoxSettingsRepository::class);

        table(['Key', 'Value', 'Description'], [
            ['database.connection', (string) config('vox.database.connection'), 'Database connection for Vox storage.'],
            ['database.path', (string) config('vox.database.path'), 'SQLite path for the Vox database.'],
            ['paths.lang', (string) config('vox.paths.lang'), 'Base language directory for translations.'],
            ['parse.output', (string) config('vox.parse.output'), 'PHP translation output format (flat or nested).'],
            ['parse.obsolete', (string) config('vox.parse.obsolete'), 'Obsolete key handling mode.'],
            ['parse.escape_unicode', config('vox.parse.escape_unicode') ? 'true' : 'false', 'Whether to escape unicode in JSON output.'],
            ['parse.max_occurrences', (string) config('vox.parse.max_occurrences', 5), 'Max occurrences stored per translation key.'],
            ['parse.keep_orphan_other_locales_keys', config('vox.parse.keep_orphan_other_locales_keys', true) ? 'true' : 'false', 'Keep keys present only in non-base locales.'],
            ['translate.driver', (string) config('vox.translate.driver'), 'Driver used for machine translation.'],
            ['translate.locales', is_array(config('vox.translate.locales')) ? json_encode(config('vox.translate.locales')) : (string) config('vox.translate.locales'), 'Locales to translate (auto or list).'],
            ['translate.base_locale', (string) config('vox.translate.base_locale'), 'Base locale used for translations.'],
            ['translate.use_context', config('vox.translate.use_context', true) ? 'true' : 'false', 'Include parse context comments in translation prompts.'],
            ['translate.model', (string) $settings->get('translate_model', config('vox.translate.model')), 'Model used for machine translation.'],
        ]);

        return self::SUCCESS;
    }
}
