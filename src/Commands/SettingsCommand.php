<?php

namespace KeypointSolutions\LaravelVox\Commands;

use Illuminate\Console\Command;
use KeypointSolutions\LaravelVox\Support\VoxListConfiguration;
use KeypointSolutions\LaravelVox\Support\VoxSettingsRepository;
use KeypointSolutions\LaravelVox\Translation\Locales\VoxLocaleDeclaration;

use function Laravel\Prompts\table;

class SettingsCommand extends Command
{
    public $signature = 'vox:settings';

    public $description = 'Display the current Vox configuration.';

    public function handle(): int
    {
        $settings = app(VoxSettingsRepository::class);
        $listConfiguration = app(VoxListConfiguration::class);
        $configuredFrontendGroups = $listConfiguration->configuredValues('vox.frontend.groups');
        $localeDeclaration = app(VoxLocaleDeclaration::class);

        table(['Key', 'Value', 'Description'], [
            ['database.connection', (string) config('vox.database.connection'), 'Database connection for Vox storage.'],
            ['database.path', (string) config('vox.database.path'), 'SQLite path for the Vox database.'],
            ['paths.lang', (string) config('vox.paths.lang'), 'Base language directory for translations.'],
            ['parse.output', (string) config('vox.parse.output'), 'PHP translation output format (flat or nested).'],
            ['parse.obsolete', (string) config('vox.parse.obsolete'), 'Obsolete key handling mode.'],
            ['parse.escape_unicode', config('vox.parse.escape_unicode') ? 'true' : 'false', 'Whether to escape unicode in JSON output.'],
            ['parse.max_occurrences', (string) config('vox.parse.max_occurrences', 5), 'Max occurrences stored per translation key.'],
            ['parse.keep_orphan_other_locales_keys', config('vox.parse.keep_orphan_other_locales_keys', true) ? 'true' : 'false', 'Keep keys present only in non-base locales.'],
            ['frontend.groups.mode', $listConfiguration->mode('vox.frontend.groups'), 'Frontend group discovery mode (auto or configured).'],
            ['frontend.groups.values', implode(', ', $configuredFrontendGroups ?? []), 'Configured frontend groups when group mode is configured.'],
            ['translate.driver', (string) config('vox.translate.driver'), 'Driver used for machine translation.'],
            ['translate.locales.mode', $this->localeModeLabel($localeDeclaration), 'Locale discovery mode (auto, configured, or callback).'],
            ['translate.locales.values', implode(', ', $localeDeclaration->locales() ?? []), 'Declared locales when locale mode is configured or callback.'],
            ['translate.base_locale', (string) config('vox.translate.base_locale'), 'Base locale used for translations.'],
            ['translate.use_context', config('vox.translate.use_context', true) ? 'true' : 'false', 'Include parse context comments in translation prompts.'],
            ['translate.model', (string) $settings->get('translate_model', config('vox.translate.model')), 'Model used for machine translation.'],
        ]);

        return self::SUCCESS;
    }

    private function localeModeLabel(VoxLocaleDeclaration $declaration): string
    {
        $configuredMode = $declaration->configuredMode();

        if ($configuredMode === VoxLocaleDeclaration::MODE_CALLBACK && ! $declaration->hasCallback()) {
            return 'callback (none registered, using auto)';
        }

        if ($configuredMode !== VoxLocaleDeclaration::MODE_CALLBACK && $declaration->hasCallback()) {
            return $configuredMode.' (registered callback ignored)';
        }

        return $configuredMode;
    }
}
