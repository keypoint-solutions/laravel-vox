<?php

namespace KeypointSolutions\LaravelVox\Translation;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use KeypointSolutions\LaravelVox\Models\VoxRemoteTranslation;
use KeypointSolutions\LaravelVox\Models\VoxTranslation;
use KeypointSolutions\LaravelVox\Models\VoxTranslationRule;
use KeypointSolutions\LaravelVox\Models\VoxTranslationValue;
use KeypointSolutions\LaravelVox\Support\VoxAuditLogger;
use KeypointSolutions\LaravelVox\Support\VoxListConfiguration;
use KeypointSolutions\LaravelVox\Support\VoxLocaleResolver;
use KeypointSolutions\LaravelVox\Support\VoxMutationLock;
use KeypointSolutions\LaravelVox\Support\VoxSettingsRepository;
use RuntimeException;

class LocaleRemover
{
    public function __construct(
        private VoxLocaleResolver $locales,
        private VoxSettingsRepository $settings,
        private TranslationFileRepository $files,
        private TranslationFileTransaction $transaction,
        private TranslationFallbackRules $rules,
        private FrontendTranslationArtifacts $artifacts,
        private VoxAuditLogger $audit,
    ) {}

    public function unavailableReason(string $locale): ?string
    {
        $normalized = $this->locales->normalizeLocaleCode($locale);
        if ($normalized === '') {
            return 'Enter a valid locale code.';
        }
        $protected = [$this->locales->resolveBaseLocale([]), config('app.locale', 'en'), config('app.fallback_locale', 'en')];
        if ($this->locales->resolveLocale($normalized, $protected) !== null) {
            return 'The default and fallback languages cannot be removed.';
        }
        $configured = app(VoxListConfiguration::class)->configuredValues('vox.translate.locales') ?? [];
        $supported = config('laravellocalization.supportedLocales', []);
        if (is_array($supported)) {
            $configured = array_merge($configured, array_keys($supported));
        }
        if ($this->locales->resolveLocale($normalized, $configured) !== null) {
            return 'Remove this language from application configuration first.';
        }
        if (! in_array($normalized, $this->settings->provisionedLocales(), true)) {
            return 'Only languages added through Vox can be removed here.';
        }

        return null;
    }

    public function remove(string $locale): void
    {
        app(VoxMutationLock::class)->run(function () use ($locale): void {
            $locale = $this->locales->normalizeLocaleCode($locale);
            if (($reason = $this->unavailableReason($locale)) !== null) {
                throw new RuntimeException($reason);
            }
            try {
                $this->transaction->run(fn (): mixed => DB::connection(config('vox.database.connection', 'vox'))->transaction(function () use ($locale): void {
                    $paths = [$this->files->langPath().'/'.$locale, $this->files->langPath().'/'.$locale.'.json'];
                    $vendor = $this->files->langPath().'/vendor';
                    if (is_link($vendor)) {
                        throw new RuntimeException('Cannot remove languages through a symbolic link.');
                    }
                    foreach (File::isDirectory($vendor) ? File::directories($vendor) : [] as $namespace) {
                        if (is_link($namespace)) {
                            throw new RuntimeException('Cannot remove languages through a symbolic link.');
                        }
                        $paths[] = $namespace.'/'.$locale;
                        $paths[] = $namespace.'/'.$locale.'.json';
                    }
                    $paths[] = $this->artifacts->pathForLocale($locale);
                    foreach ($paths as $path) {
                        if (is_link($path)) {
                            throw new RuntimeException('Cannot remove a symbolic link: '.$path);
                        }
                        if (File::isDirectory($path)) {
                            $this->transaction->deleteDirectory($path);
                        } elseif (File::exists($path)) {
                            $this->transaction->delete($path);
                        }
                    }
                    $translationIds = VoxTranslationValue::query()->where('locale', $locale)->pluck('translation_id');
                    $values = VoxTranslationValue::query()->where('locale', $locale)->delete();
                    foreach (VoxTranslation::query()->whereIn('id', $translationIds)->lazyById() as $translation) {
                        $translation->refreshApproval();
                    }
                    VoxRemoteTranslation::query()->where('locale', $locale)->delete();
                    VoxTranslationRule::query()->where('locale', $locale)->delete();
                    if (! $this->settings->save(['provisioned_locales' => array_values(array_diff($this->settings->provisionedLocales(), [$locale]))])) {
                        throw new RuntimeException('Settings storage is unavailable.');
                    }
                    $this->rules->clear();
                    $this->rules->writeManifest($this->files->langPath());
                    $this->audit->record('locale-removed', ['locale' => $locale, 'values' => $values]);
                }));
            } finally {
                $this->rules->clear();
            }
        }, 'removing language '.$locale);
    }
}
