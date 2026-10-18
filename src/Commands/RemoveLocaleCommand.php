<?php

namespace KeypointSolutions\LaravelVox\Commands;

use Illuminate\Console\Command;
use KeypointSolutions\LaravelVox\Translation\Locales\LocaleRemover;
use KeypointSolutions\LaravelVox\Translation\Locales\VoxLocaleCatalog;
use KeypointSolutions\LaravelVox\Translation\Locales\VoxLocaleResolver;
use Throwable;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\info;

class RemoveLocaleCommand extends Command
{
    public $signature = 'vox:remove-locale
        {locale : Locale code of a language added through Vox}
        {--force : Remove without asking for confirmation}';

    public $description = 'Remove a language added through Vox, including its files, translations, and review data.';

    public function handle(VoxLocaleResolver $localeResolver, VoxLocaleCatalog $catalog, LocaleRemover $remover): int
    {
        $locale = $localeResolver->normalizeLocaleCode((string) $this->argument('locale'));

        if (($reason = $remover->unavailableReason($locale)) !== null) {
            $this->error($reason);

            return self::FAILURE;
        }

        $name = $catalog->displayName($locale);

        if (! $this->option('force')) {
            if (! $this->input->isInteractive()) {
                $this->error('Pass --force to remove a language without confirmation.');

                return self::FAILURE;
            }

            if (! confirm("Permanently remove {$name} ({$locale}), including its files, translations, and review data?", default: false)) {
                return self::FAILURE;
            }
        }

        try {
            $remover->remove($locale);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        info("Removed {$name} ({$locale}).");

        return self::SUCCESS;
    }
}
