<?php

namespace KeypointSolutions\LaravelVox\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use KeypointSolutions\LaravelVox\Models\VoxTranslation;
use KeypointSolutions\LaravelVox\Models\VoxTranslationValue;
use KeypointSolutions\LaravelVox\Support\VoxLocaleResolver;
use KeypointSolutions\LaravelVox\Translation\Drivers\TranslationDriverFactory;
use Throwable;

class ManageTranslationController
{
    public function __construct(private VoxLocaleResolver $localeResolver) {}

    public function update(Request $request, VoxTranslation $translation): RedirectResponse
    {
        $validated = $request->validate([
            'values' => ['required', 'array'],
            'values.*' => ['nullable', 'string'],
        ]);

        [$locales] = $this->resolveLocales();

        foreach ($validated['values'] as $locale => $value) {
            if (! is_string($locale) || ! in_array($locale, $locales, true)) {
                continue;
            }

            VoxTranslationValue::query()->updateOrCreate(
                [
                    'translation_id' => $translation->id,
                    'locale' => $locale,
                ],
                [
                    'value' => is_string($value) ? $value : '',
                    'is_obsolete' => false,
                ]
            );
        }

        $translation->touch();

        return Inertia::flash('success', 'Translations saved.')->back();
    }

    /**
     * @return array{0: array<int, string>, 1: string}
     */
    private function resolveLocales(): array
    {
        $locales = $this->localeResolver->resolveLocales();
        $baseLocale = $this->localeResolver->resolveBaseLocale($locales);

        if (! in_array($baseLocale, $locales, true)) {
            $locales[] = $baseLocale;
        }

        $locales = array_values(array_unique($locales));
        $locales = array_values(array_unique(array_merge([$baseLocale], $locales)));

        return [$locales, $baseLocale];
    }

    public function toggleApproval(VoxTranslation $translation): RedirectResponse
    {
        $translation->status = $translation->status === 'approved' ? 'pending' : 'approved';
        $translation->save();

        return redirect()->back();
    }

    public function translate(
        Request $request,
        VoxTranslation $translation,
        TranslationDriverFactory $driverFactory
    ): RedirectResponse {
        $validated = $request->validate([
            'locales' => ['nullable', 'array'],
            'locales.*' => ['string'],
            'base_value' => ['nullable', 'string'],
        ]);

        $driverName = (string) config('vox.translate.driver', 'openai');
        if ($driverName === 'null') {
            return redirect()->back()->withErrors(['translate' => 'AI translation is not configured.']);
        }

        [$availableLocales, $baseLocale] = $this->resolveLocales();
        $targetLocales = $this->resolveTargetLocales(
            $validated['locales'] ?? [],
            $availableLocales,
            $baseLocale
        );

        if ($targetLocales === []) {
            return redirect()->back()->withErrors(['translate' => 'No target locales selected.']);
        }

        $baseValue = $validated['base_value'] ?? null;
        if (! is_string($baseValue) || $baseValue === '') {
            $baseValue = $translation->values()->where('locale', $baseLocale)->value('value');
        }

        if (! is_string($baseValue) || $baseValue === '') {
            return redirect()->back()->withErrors(['translate' => 'Base locale value is required before translating.']);
        }

        $context = $this->buildTranslationContext($translation);
        $driver = $driverFactory->make();

        /** @var array<string, string> $translatedValues */
        $translatedValues = [];

        try {
            foreach ($targetLocales as $locale) {
                $translated = $driver->translate($baseValue, $baseLocale, $locale, $context);
                $translatedValues[$locale] = $translated;
            }
        } catch (Throwable $exception) {
            return redirect()->back()->withErrors(['translate' => $exception->getMessage()]);
        }

        return Inertia::flash('translated_values', $translatedValues)->back();
    }

    /**
     * @param  array<int, string>  $requested
     * @param  array<int, string>  $available
     * @return array<int, string>
     */
    private function resolveTargetLocales(array $requested, array $available, string $baseLocale): array
    {
        $requested = array_values(array_filter($requested, fn ($locale) => is_string($locale) && $locale !== ''));

        if ($requested === []) {
            $requested = $available;
        }

        $targets = array_values(array_intersect($available, $requested));
        $targets = array_values(array_filter($targets, fn ($locale) => $locale !== $baseLocale));

        return $targets;
    }

    /**
     * @return array<string, string>
     */
    private function buildTranslationContext(VoxTranslation $translation): array
    {
        if (! config('vox.translate.use_context', true)) {
            return [];
        }

        $occurrence = $translation->occurrences()->orderBy('id')->first();
        if ($occurrence === null) {
            return [];
        }

        $segments = [];

        if (is_string($occurrence->context_before) && $occurrence->context_before !== '') {
            $segments[] = $occurrence->context_before;
        }

        if (is_string($occurrence->context_after) && $occurrence->context_after !== '') {
            $segments[] = $occurrence->context_after;
        }

        if ($segments === []) {
            return [];
        }

        $context = trim(implode(' ', $segments));

        if (is_string($occurrence->file_path) && $occurrence->file_path !== '') {
            $line = $occurrence->line_number;
            $prefix = $line !== null ? $occurrence->file_path.':'.$line : $occurrence->file_path;
            $context = $prefix.' '.$context;
        }

        return ['context' => $context];
    }
}
