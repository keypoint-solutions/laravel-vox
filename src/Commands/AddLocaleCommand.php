<?php

namespace KeypointSolutions\LaravelVox\Commands;

use Illuminate\Console\Command;
use KeypointSolutions\LaravelVox\Ai\AiAvailability;
use KeypointSolutions\LaravelVox\Translation\Locales\LocaleProvisioner;
use KeypointSolutions\LaravelVox\Translation\Locales\VoxLocaleCatalog;
use KeypointSolutions\LaravelVox\Translation\Locales\VoxLocaleResolver;
use Throwable;

use function Laravel\Prompts\info;
use function Laravel\Prompts\text;
use function Laravel\Prompts\warning;

class AddLocaleCommand extends Command
{
    public $signature = 'vox:add-locale
        {locale? : Locale code such as de, pt_BR, or zh_Hant}
        {--translate : AI translate the new values; values it cannot translate are flagged}
        {--follow-default : Follow the base locale wording, including new keys, until translated}';

    public $description = 'Add a language created from the base locale files.';

    public function handle(VoxLocaleResolver $localeResolver, VoxLocaleCatalog $catalog, LocaleProvisioner $provisioner, AiAvailability $ai): int
    {
        $translate = (bool) $this->option('translate');
        $followDefault = (bool) $this->option('follow-default');

        if ($translate && $followDefault) {
            $this->error('Choose either --translate or --follow-default.');

            return self::FAILURE;
        }

        if ($translate && ($reason = $ai->reason()) !== null) {
            $this->error($reason);

            return self::FAILURE;
        }

        $requested = $this->argument('locale') ?? ($this->input->isInteractive()
            ? text('Locale code', placeholder: 'de, pt_BR, or zh_Hant', required: true)
            : '');
        $locale = $localeResolver->normalizeLocaleCode((string) $requested);

        if ($locale === '') {
            $this->error('Enter a valid locale code such as de, pt_BR, or zh_Hant.');

            return self::FAILURE;
        }

        if (! $localeResolver->canProvision($locale)) {
            $this->error("Locale [{$locale}] is already defined.");

            return self::FAILURE;
        }

        try {
            $result = $provisioner->provision($locale, $translate, $followDefault);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $name = $catalog->displayName($result->locale);

        if ($followDefault) {
            info("Added {$name} ({$result->locale}) following the base locale wording. Publish to create its language files.");
        } elseif ($translate) {
            info("Added {$name} ({$result->locale}): AI translated {$result->translatedValues} of {$result->values} values; the rest are flagged for translation.");
        } else {
            info("Added {$name} ({$result->locale}) with {$result->values} values flagged for translation.");
        }

        if (($localeWarning = $catalog->warning($result->locale)) !== null) {
            warning($localeWarning);
        }

        if ($result->translationFailure !== null) {
            $this->error('AI translation stopped early: '.$result->translationFailure);
            $this->line('The language was added; run vox:translate --locale='.$result->locale.' to continue.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
