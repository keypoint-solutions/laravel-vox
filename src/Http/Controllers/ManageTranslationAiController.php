<?php

namespace KeypointSolutions\LaravelVox\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use KeypointSolutions\LaravelVox\Ai\AiAvailability;
use KeypointSolutions\LaravelVox\Models\VoxTranslation;
use KeypointSolutions\LaravelVox\Models\VoxTranslationValue;
use KeypointSolutions\LaravelVox\Support\VoxAuditLogger;
use KeypointSolutions\LaravelVox\Support\VoxConfig;
use KeypointSolutions\LaravelVox\Translation\Drivers\TranslationDriver;
use KeypointSolutions\LaravelVox\Translation\Drivers\TranslationDriverFactory;
use KeypointSolutions\LaravelVox\Translation\Locales\VoxLocaleResolver;
use KeypointSolutions\LaravelVox\Translation\TranslationEligibility;
use KeypointSolutions\LaravelVox\Translation\TranslationFallbackRules;
use KeypointSolutions\LaravelVox\Translation\TranslationKey;
use Throwable;

class ManageTranslationAiController
{
    private const LOCALE_BATCH_SIZE = 25;

    /**
     * Seconds after which a request stops starting new AI calls and hands the rest back to the page, keeping each
     * request well inside PHP's max_execution_time and proxy timeouts.
     */
    private const REQUEST_TIME_BUDGET_SECONDS = 20;

    /**
     * Execution time granted before each AI call: the provider timeout plus room to save the results.
     */
    private const AI_CALL_TIME_LIMIT_SECONDS = 90;

    public function __construct(
        private VoxLocaleResolver $localeResolver,
        private TranslationEligibility $eligibility,
        private TranslationDriverFactory $drivers,
        private AiAvailability $ai,
    ) {}

    /**
     * Translate wording that has not been saved yet, such as a dynamic translation being created.
     */
    public function translateDraft(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'locales' => ['nullable', 'array'],
            'locales.*' => ['string'],
            'base_value' => ['required', 'string'],
            'key' => ['nullable', 'string', 'max:500'],
        ]);

        $key = trim((string) ($validated['key'] ?? ''));
        $identity = TranslationKey::fromRaw($key);

        return $this->translateToFlash(
            $validated['locales'] ?? [],
            $validated['base_value'],
            (string) ($validated['key'] ?? ''),
            $identity->group,
            $identity->key,
            $key === '' ? [] : ['context' => "Laravel translation key: {$key}"],
        );
    }

    /**
     * Translate the wording being edited for one key. Nothing is saved: the result is returned to the editor.
     */
    public function translate(Request $request, VoxTranslation $translation): RedirectResponse
    {
        abort_if($translation->is_pending_delete, 422, 'Cancel deletion before editing this key.');
        $validated = $request->validate([
            'locales' => ['nullable', 'array'],
            'locales.*' => ['string'],
            'base_value' => ['nullable', 'string'],
        ]);

        return $this->translateToFlash(
            $validated['locales'] ?? [],
            $validated['base_value'] ?? null,
            $translation->key,
            $translation->group,
            $translation->key,
            fn (): array => $this->buildTranslationContext($translation),
            $translation,
        );
    }

    /**
     * Fill the missing target values of the selected translations and save them as drafts. A request stops once its
     * time budget is spent and returns the ids it did not reach; the page repeats the request with them until none
     * remain.
     */
    public function bulkTranslate(Request $request, VoxAuditLogger $auditLogger): RedirectResponse
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['required', 'integer', 'distinct'],
        ]);

        if (($reason = $this->ai->reason()) !== null) {
            return $this->failed($reason);
        }

        [$availableLocales, $baseLocale] = $this->localeResolver->managedLocalesWithBase();
        $targetLocales = $this->resolveTargetLocales([], $availableLocales, $baseLocale);
        $translations = VoxTranslation::query()
            ->with(['values', 'occurrences'])
            ->where('is_pending_delete', false)
            ->whereIn('id', $validated['ids'])
            ->orderBy('id')
            ->get()
            ->values();
        $driver = $this->drivers->make();
        $deadline = now()->addSeconds(self::REQUEST_TIME_BUDGET_SECONDS);

        /** @var array<int, array{translation: VoxTranslation, values: array<string, string>}> $translated */
        $translated = [];
        $failure = null;
        $remainingIds = [];

        foreach ($translations as $index => $translation) {
            if ($translated !== [] && now()->greaterThanOrEqualTo($deadline)) {
                $remainingIds = $translations->slice($index)->pluck('id')->values()->all();

                break;
            }

            if ($translation->is_orphan || $translation->is_pending_delete) {
                continue;
            }

            $values = $translation->values->keyBy('locale');
            $baseValue = $values->get($baseLocale)?->value;

            if (! $this->eligibility->canTranslateSource($translation->key, $baseValue)) {
                continue;
            }

            $translatedValues = [];

            try {
                $this->translateLocales(
                    $driver,
                    array_filter(
                        $targetLocales,
                        fn (string $locale): bool => $this->eligibility->isMissing($values->get($locale)?->value)
                    ),
                    $baseValue,
                    $baseLocale,
                    $translation->group,
                    $translation->key,
                    $this->buildTranslationContext($translation),
                    $translatedValues,
                );
            } catch (Throwable $exception) {
                $failure = $exception;
            }

            if ($translatedValues !== []) {
                $translated[] = [
                    'translation' => $translation,
                    'values' => $translatedValues,
                ];
            }

            if ($failure !== null) {
                break;
            }
        }

        $translatedValueCount = array_sum(array_map(
            fn (array $result): int => count($result['values']),
            $translated
        ));
        $translatedIds = array_map(
            fn (array $result): int => $result['translation']->id,
            $translated
        );

        if ($translated !== []) {
            VoxConfig::connection()
                ->transaction(function () use ($translated, $translatedIds, $translatedValueCount, $auditLogger): void {
                    foreach ($translated as $result) {
                        foreach ($result['values'] as $locale => $value) {
                            VoxTranslationValue::query()->firstOrNew([
                                'translation_id' => $result['translation']->id,
                                'locale' => $locale,
                            ])->saveDraft($value);
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
        }

        return Inertia::flash('bulk_translation', [
            'translations' => count($translatedIds),
            'values' => $translatedValueCount,
            'remaining_ids' => $failure === null ? $remainingIds : [],
            'failure' => $failure?->getMessage(),
        ])->back();
    }

    /**
     * Translate the next batch of missing values in one locale and save them as drafts. A batch ends after
     * LOCALE_BATCH_SIZE values or once the request's time budget is spent; the page repeats the request with the
     * returned cursor until it is null, so a locale of any size is translated in short requests.
     */
    public function translateLocale(Request $request, VoxAuditLogger $auditLogger): RedirectResponse
    {
        $validated = $request->validate([
            'locale' => ['required', 'string'],
            'after_id' => ['sometimes', 'integer', 'min:0'],
        ]);

        if (($reason = $this->ai->reason()) !== null) {
            return $this->failed($reason);
        }

        [$availableLocales, $baseLocale] = $this->localeResolver->managedLocalesWithBase();
        $locale = $this->localeResolver->resolveLocale($validated['locale'], $availableLocales);

        if ($locale === null || $locale === $baseLocale) {
            return $this->failed('Choose a target locale to translate.');
        }

        $afterId = (int) ($validated['after_id'] ?? 0);
        $total = $afterId === 0 ? $this->countLocaleCandidates($locale, $baseLocale) : null;
        $deadline = now()->addSeconds(self::REQUEST_TIME_BUDGET_SECONDS);
        $driver = $this->drivers->make();
        $translated = [];
        $failure = null;
        $nextAfterId = null;

        foreach ($this->localeCandidates($locale, $baseLocale, $afterId) as $translation) {
            if ($translated !== []
                && (count($translated) === self::LOCALE_BATCH_SIZE || now()->greaterThanOrEqualTo($deadline))) {
                $nextAfterId = end($translated)['translation']->id;

                break;
            }

            $this->extendTimeLimitForAiCall();

            try {
                $translated[] = [
                    'translation' => $translation,
                    'value' => $driver->translate(
                        $translation->values->firstWhere('locale', $baseLocale)->value,
                        $baseLocale,
                        $locale,
                        $this->buildTranslationContext($translation),
                    ),
                ];
            } catch (Throwable $exception) {
                $failure = $exception;

                break;
            }
        }

        if ($translated !== []) {
            VoxConfig::connection()->transaction(function () use ($translated, $locale, $auditLogger): void {
                foreach ($translated as $result) {
                    VoxTranslationValue::query()->firstOrNew([
                        'translation_id' => $result['translation']->id,
                        'locale' => $locale,
                    ])->saveDraft($result['value']);

                    $result['translation']->status = 'pending';
                    $result['translation']->save();
                }

                $auditLogger->record('locale-translated', [
                    'locale' => $locale,
                    'translation_ids' => array_map(fn (array $result): int => $result['translation']->id, $translated),
                    'values' => count($translated),
                ]);
            });
        }

        return Inertia::flash('locale_translation', [
            'locale' => $locale,
            'translated' => count($translated),
            'total' => $total,
            'next_after_id' => $failure === null ? $nextAfterId : null,
            'failure' => $failure?->getMessage(),
        ])->back();
    }

    private function countLocaleCandidates(string $locale, string $baseLocale): int
    {
        $count = 0;

        foreach ($this->localeCandidates($locale, $baseLocale, 0, withOccurrences: false) as $translation) {
            $count++;
        }

        return $count;
    }

    /**
     * Translations after the cursor whose value in the locale is missing and can be translated from the base wording.
     *
     * @return \Generator<int, VoxTranslation>
     */
    private function localeCandidates(string $locale, string $baseLocale, int $afterId, bool $withOccurrences = true): \Generator
    {
        $rules = app(TranslationFallbackRules::class);
        $translations = VoxTranslation::query()
            ->with($withOccurrences ? ['values', 'occurrences'] : ['values'])
            ->where('is_pending_delete', false)
            ->where('is_orphan', false)
            ->where('id', '>', $afterId)
            ->lazyById(200);

        foreach ($translations as $translation) {
            $values = $translation->values->keyBy('locale');

            if ($this->eligibility->isMissing($values->get($locale)?->value)
                && $this->eligibility->canTranslateSource($translation->key, $values->get($baseLocale)?->value)
                && ! $rules->usesDefault($locale, $translation->group, $translation->key)) {
                yield $translation;
            }
        }
    }

    /**
     * Translate one source value into the requested locales and flash the result back to the editor.
     *
     * @param  array<int, string>  $requestedLocales
     * @param  array<string, string>|callable(): array<string, string>  $context
     * @param  VoxTranslation|null  $translation  Supplies the saved base value when the editor sent none.
     */
    private function translateToFlash(
        array $requestedLocales,
        ?string $baseValue,
        string $sourceKey,
        ?string $group,
        string $key,
        array|callable $context,
        ?VoxTranslation $translation = null,
    ): RedirectResponse {
        if (($reason = $this->ai->reason()) !== null) {
            return $this->failed($reason);
        }

        [$availableLocales, $baseLocale] = $this->localeResolver->managedLocalesWithBase();
        $targetLocales = $this->resolveTargetLocales($requestedLocales, $availableLocales, $baseLocale);

        if ($targetLocales === []) {
            return $this->failed('No target locales selected.');
        }

        if ($translation !== null && ($baseValue === null || $baseValue === '')) {
            $baseValue = $translation->values()->where('locale', $baseLocale)->value('value');
        }

        if (! $this->eligibility->canTranslateSource($sourceKey, $baseValue)) {
            return $this->failed('Base locale value is required before translating.');
        }

        try {
            return Inertia::flash('translated_values', $this->translateLocales(
                $this->drivers->make(),
                $targetLocales,
                $baseValue,
                $baseLocale,
                $group,
                $key,
                is_callable($context) ? $context() : $context,
            ))->back();
        } catch (Throwable $exception) {
            return $this->failed($exception->getMessage());
        }
    }

    /**
     * Translate a source value into each locale that keeps its own wording.
     *
     * @param  array<int, string>  $locales
     * @param  array<string, string>  $context
     * @param  array<string, string>  $translated  Filled as each locale completes, so finished work survives a later failure.
     * @return array<string, string>
     */
    private function translateLocales(
        TranslationDriver $driver,
        array $locales,
        string $baseValue,
        string $baseLocale,
        ?string $group,
        string $key,
        array $context,
        array &$translated = [],
    ): array {
        foreach ($locales as $locale) {
            if (app(TranslationFallbackRules::class)->usesDefault($locale, $group, $key)) {
                continue;
            }

            $this->extendTimeLimitForAiCall();
            $translated[$locale] = $driver->translate($baseValue, $baseLocale, $locale, $context);
        }

        return $translated;
    }

    /**
     * Restart PHP's execution timer so a slow provider response cannot end the request with a fatal error. Runtimes
     * without a limit, such as the CLI, are left unlimited.
     */
    private function extendTimeLimitForAiCall(): void
    {
        $limit = (int) ini_get('max_execution_time');

        if ($limit > 0 && function_exists('set_time_limit')) {
            set_time_limit(max($limit, self::AI_CALL_TIME_LIMIT_SECONDS));
        }
    }

    private function failed(string $message): RedirectResponse
    {
        return redirect()->back()->withErrors(['translate' => $message]);
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

        return array_values(array_filter($targets, fn ($locale) => $locale !== $baseLocale));
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
