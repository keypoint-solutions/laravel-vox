<?php

namespace KeypointSolutions\LaravelVox\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use KeypointSolutions\LaravelVox\Models\VoxTranslation;
use KeypointSolutions\LaravelVox\Models\VoxTranslationValue;
use KeypointSolutions\LaravelVox\Support\VoxAuditLogger;
use KeypointSolutions\LaravelVox\Support\VoxLocaleResolver;
use KeypointSolutions\LaravelVox\Translation\Drivers\TranslationDriverFactory;
use Throwable;

class ManageTranslationController
{
    public function __construct(private VoxLocaleResolver $localeResolver) {}

    public function update(
        Request $request,
        VoxTranslation $translation,
        VoxAuditLogger $auditLogger,
    ): RedirectResponse {
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
        $auditLogger->record('translation-updated', [
            'translation_id' => $translation->id,
            'locales' => array_values(array_intersect($locales, array_keys($validated['values']))),
        ]);

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

    public function toggleApproval(VoxTranslation $translation, VoxAuditLogger $auditLogger): RedirectResponse
    {
        $status = $translation->status === 'approved' ? 'pending' : 'approved';

        $translation->timestamps = false;
        $translation->status = $status;
        $translation->save();
        $translation->timestamps = true;

        $auditLogger->record(
            $status === 'approved' ? 'translation-approved' : 'translation-reopened',
            ['translation_id' => $translation->id]
        );

        $message = $status === 'approved' ? 'Translation approved.' : 'Translation returned to review.';

        return Inertia::flash('success', $message)->back();
    }

    public function bulkApproval(Request $request, VoxAuditLogger $auditLogger): RedirectResponse
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['required', 'integer', 'distinct'],
            'status' => ['required', Rule::in(['pending', 'approved'])],
        ]);
        $status = $validated['status'];
        $ids = VoxTranslation::query()
            ->whereIn('id', $validated['ids'])
            ->pluck('id')
            ->all();

        VoxTranslation::query()
            ->whereIn('id', $ids)
            ->toBase()
            ->update(['status' => $status]);

        $action = $status === 'approved' ? 'translations-bulk-approved' : 'translations-bulk-reopened';
        $auditLogger->record($action, [
            'translation_ids' => $ids,
            'count' => count($ids),
        ]);

        $verb = $status === 'approved' ? 'Approved' : 'Returned to review';
        $noun = count($ids) === 1 ? 'translation' : 'translations';

        return Inertia::flash('success', "{$verb} ".count($ids)." {$noun}.")->back();
    }

    public function bulkTranslate(
        Request $request,
        TranslationDriverFactory $driverFactory,
        VoxAuditLogger $auditLogger,
    ): RedirectResponse {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['required', 'integer', 'distinct'],
        ]);

        if (config('vox.translate.driver', 'openai') === 'null') {
            return redirect()->back()->withErrors(['translate' => 'AI translation is not configured.']);
        }

        [$availableLocales, $baseLocale] = $this->resolveLocales();
        $targetLocales = array_values(array_filter(
            $availableLocales,
            fn (string $locale): bool => $locale !== $baseLocale
        ));
        $translations = VoxTranslation::query()
            ->with(['values', 'occurrences'])
            ->whereIn('id', $validated['ids'])
            ->get();
        $driver = $driverFactory->make();
        $missingPrefix = (string) config('vox.parse.missing_translation_prefix', '🚩');

        /** @var array<int, array{translation: VoxTranslation, values: array<string, string>}> $translated */
        $translated = [];

        try {
            foreach ($translations as $translation) {
                if ($translation->is_orphan) {
                    continue;
                }

                $values = $translation->values->keyBy('locale');
                $baseValue = $values->get($baseLocale)?->value;

                if ($this->isMissingValue($baseValue, $missingPrefix)) {
                    continue;
                }

                $translatedValues = [];
                $context = $this->buildTranslationContext($translation);

                foreach ($targetLocales as $locale) {
                    if (! $this->isMissingValue($values->get($locale)?->value, $missingPrefix)) {
                        continue;
                    }

                    $translatedValues[$locale] = $driver->translate(
                        $baseValue,
                        $baseLocale,
                        $locale,
                        $context
                    );
                }

                if ($translatedValues !== []) {
                    $translated[] = [
                        'translation' => $translation,
                        'values' => $translatedValues,
                    ];
                }
            }
        } catch (Throwable $exception) {
            return redirect()->back()->withErrors(['translate' => $exception->getMessage()]);
        }

        if ($translated === []) {
            return Inertia::flash(
                'success',
                'No missing target values were found in the selected translations.'
            )->back();
        }

        $translatedValueCount = array_sum(array_map(
            fn (array $result): int => count($result['values']),
            $translated
        ));
        $translatedIds = array_map(
            fn (array $result): int => $result['translation']->id,
            $translated
        );

        DB::connection(config('vox.database.connection', 'vox'))
            ->transaction(function () use ($translated, $translatedIds, $translatedValueCount, $auditLogger): void {
                foreach ($translated as $result) {
                    foreach ($result['values'] as $locale => $value) {
                        VoxTranslationValue::query()->updateOrCreate(
                            [
                                'translation_id' => $result['translation']->id,
                                'locale' => $locale,
                            ],
                            [
                                'value' => $value,
                                'is_obsolete' => false,
                            ]
                        );
                    }

                    $result['translation']->status = 'pending';
                    $result['translation']->save();
                }

                $auditLogger->record('translations-bulk-translated', [
                    'translation_ids' => $translatedIds,
                    'translations' => count($translatedIds),
                    'values' => $translatedValueCount,
                ]);
            });

        $translationNoun = count($translatedIds) === 1 ? 'translation' : 'translations';
        $valueNoun = $translatedValueCount === 1 ? 'value' : 'values';

        return Inertia::flash(
            'success',
            "AI translated {$translatedValueCount} missing {$valueNoun} across "
                .count($translatedIds)." {$translationNoun}."
        )->back();
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

    private function isMissingValue(mixed $value, string $missingPrefix): bool
    {
        return ! is_string($value)
            || $value === ''
            || Str::startsWith($value, $missingPrefix);
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
