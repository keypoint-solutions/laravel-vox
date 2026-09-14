<?php

namespace KeypointSolutions\LaravelVox\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use KeypointSolutions\LaravelVox\Models\VoxTranslation;
use KeypointSolutions\LaravelVox\Models\VoxTranslationValue;
use KeypointSolutions\LaravelVox\Support\VoxAuditLogger;
use KeypointSolutions\LaravelVox\Support\VoxDynamicKeyRegistry;
use KeypointSolutions\LaravelVox\Support\VoxFrontendManifest;
use KeypointSolutions\LaravelVox\Support\VoxLocaleResolver;
use KeypointSolutions\LaravelVox\Translation\Drivers\TranslationDriverFactory;
use KeypointSolutions\LaravelVox\Translation\TranslationKey;
use Throwable;

class ManageTranslationController
{
    public function __construct(
        private VoxLocaleResolver $localeResolver,
        private VoxDynamicKeyRegistry $dynamicKeys,
    ) {}

    public function store(
        Request $request,
        VoxAuditLogger $auditLogger,
        VoxFrontendManifest $frontendManifest,
    ): RedirectResponse {
        $patterns = array_column($this->dynamicKeys->entries(), 'pattern');
        $validated = $request->validate([
            'pattern' => ['required', 'string', Rule::in($patterns)],
            'key' => ['required', 'string', 'max:500'],
            'values' => ['required', 'array'],
            'values.*' => ['nullable', 'string'],
        ]);
        $fullKey = trim($validated['key']);

        if ($fullKey === '' || str_contains($fullKey, '*') || preg_match('/[\r\n]/', $fullKey) === 1) {
            throw ValidationException::withMessages([
                'key' => 'Enter one concrete translation key without wildcards or line breaks.',
            ]);
        }

        if (! $this->dynamicKeys->patternAccepts($validated['pattern'], $fullKey)) {
            throw ValidationException::withMessages([
                'key' => "The translation key must match {$validated['pattern']}.",
            ]);
        }

        [$locales, $baseLocale] = $this->resolveLocales();
        $baseValue = $validated['values'][$baseLocale] ?? null;

        if (! is_string($baseValue) || trim($baseValue) === '') {
            throw ValidationException::withMessages([
                "values.{$baseLocale}" => "The {$baseLocale} source value is required.",
            ]);
        }

        $translationKey = TranslationKey::fromRaw($fullKey);
        $group = $translationKey->group ?? 'json';

        if (VoxTranslation::query()->where('key', $translationKey->key)->where('group', $group)->exists()) {
            throw ValidationException::withMessages([
                'key' => 'That translation key already exists.',
            ]);
        }

        $match = $this->dynamicKeys->match(
            $translationKey->key,
            $group === 'json' ? null : $group
        );

        if ($match === null) {
            throw ValidationException::withMessages([
                'key' => 'The translation key is not covered by an active dynamic pattern.',
            ]);
        }

        $translation = DB::connection(config('vox.database.connection', 'vox'))
            ->transaction(function () use (
                $translationKey,
                $group,
                $match,
                $locales,
                $validated,
                $auditLogger
            ): VoxTranslation {
                $translation = VoxTranslation::query()->create([
                    'key' => $translationKey->key,
                    'group' => $group,
                    'is_frontend' => $match['is_frontend'],
                    'is_orphan' => false,
                    'source' => 'dynamic',
                    'status' => 'pending',
                ]);

                foreach ($locales as $locale) {
                    $value = $validated['values'][$locale] ?? '';

                    VoxTranslationValue::query()->create([
                        'translation_id' => $translation->id,
                        'locale' => $locale,
                        'value' => is_string($value) ? $value : '',
                        'is_obsolete' => false,
                    ]);
                }

                $auditLogger->record('dynamic-translation-created', [
                    'translation_id' => $translation->id,
                    'key' => $translationKey->fullKey(),
                    'pattern' => $match['pattern'],
                ]);

                return $translation;
            });

        if ($translation->is_frontend) {
            $frontendManifest->writeFromDatabase();
        }

        return Inertia::flash('success', "Dynamic translation {$fullKey} created.")->back();
    }

    public function translateDraft(
        Request $request,
        TranslationDriverFactory $driverFactory,
    ): RedirectResponse {
        $validated = $request->validate([
            'locales' => ['nullable', 'array'],
            'locales.*' => ['string'],
            'base_value' => ['required', 'string'],
            'key' => ['nullable', 'string', 'max:500'],
        ]);

        if (config('vox.translate.driver', 'openai') === 'null') {
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

        $baseValue = $validated['base_value'];

        if (trim($baseValue) === '') {
            return redirect()->back()->withErrors([
                'translate' => 'Base locale value is required before translating.',
            ]);
        }

        $key = trim((string) ($validated['key'] ?? ''));
        $context = $key === '' ? [] : ['context' => "Laravel translation key: {$key}"];
        $driver = $driverFactory->make();
        $translatedValues = [];

        try {
            foreach ($targetLocales as $locale) {
                $translatedValues[$locale] = $driver->translate(
                    $baseValue,
                    $baseLocale,
                    $locale,
                    $context
                );
            }
        } catch (Throwable $exception) {
            return redirect()->back()->withErrors(['translate' => $exception->getMessage()]);
        }

        return Inertia::flash('translated_values', $translatedValues)->back();
    }

    public function update(
        Request $request,
        VoxTranslation $translation,
        VoxAuditLogger $auditLogger,
    ): RedirectResponse {
        abort_if($translation->is_ignored, 422, 'Restore this ignored key before editing it.');
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
        abort_if($translation->is_ignored, 422, 'Restore this ignored key before editing it.');
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
            ->where('is_ignored', false)
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
            ->where('is_ignored', false)
            ->whereIn('id', $validated['ids'])
            ->get();
        $driver = $driverFactory->make();
        $missingPrefix = (string) config('vox.parse.missing_translation_prefix', '🚩');

        /** @var array<int, array{translation: VoxTranslation, values: array<string, string>}> $translated */
        $translated = [];

        try {
            foreach ($translations as $translation) {
                if ($translation->is_orphan || $translation->is_ignored) {
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
        abort_if($translation->is_ignored, 422, 'Restore this ignored key before editing it.');
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
