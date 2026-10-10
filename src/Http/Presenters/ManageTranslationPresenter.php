<?php

namespace KeypointSolutions\LaravelVox\Http\Presenters;

use Carbon\CarbonInterface;
use Illuminate\Support\Str;
use KeypointSolutions\LaravelVox\Models\VoxTranslation;
use KeypointSolutions\LaravelVox\Translation\Locales\VoxLocaleResolver;
use KeypointSolutions\LaravelVox\Translation\Scanning\VoxDynamicKeyRegistry;
use KeypointSolutions\LaravelVox\Translation\TranslationDeletionEligibility;
use KeypointSolutions\LaravelVox\Translation\TranslationEligibility;
use KeypointSolutions\LaravelVox\Translation\TranslationFallbackRules;

/**
 * Shapes one translation, with its values and occurrences, into a Manage page row.
 */
class ManageTranslationPresenter
{
    public function __construct(
        private VoxLocaleResolver $localeResolver,
        private VoxDynamicKeyRegistry $dynamicKeys,
        private TranslationDeletionEligibility $deletionEligibility,
        private TranslationEligibility $eligibility,
    ) {}

    /**
     * @param  array<int, string>  $locales
     * @return array<string, mixed>
     */
    public function present(VoxTranslation $translation, array $locales, ?CarbonInterface $lastSyncAt): array
    {
        $values = array_fill_keys($locales, '');
        $dynamicMatch = $this->dynamicKeys->match(
            $translation->key,
            $translation->group === 'json' ? null : $translation->group
        );

        foreach ($translation->values as $value) {
            $values[$value->locale] = $value->value;
        }

        return [
            'id' => $translation->id,
            'group' => $translation->group,
            'key' => $translation->key,
            'display_key' => $this->displayKey($translation),
            'status' => $translation->status,
            'freshness_status' => $this->freshnessStatus($translation, $lastSyncAt),
            'has_missing_values' => $this->hasMissingValues($translation, $locales),
            'is_frontend' => $translation->is_frontend,
            'is_orphan' => $translation->is_orphan,
            'is_dynamic' => in_array('detected', $dynamicMatch['sources'] ?? [], true) || in_array('binding', $dynamicMatch['sources'] ?? [], true),
            'is_retained' => array_intersect(['settings', 'retained-config'], $dynamicMatch['sources'] ?? []) !== [],
            'is_pending_delete' => $translation->is_pending_delete,
            'is_placeholder_key' => $this->eligibility->isPlaceholderKey($translation->key),
            'deletion_unavailable_reason' => $this->deletionEligibility->reason($translation),
            'retention_sources' => $dynamicMatch['sources'] ?? [],
            'matching_patterns' => $dynamicMatch['patterns'] ?? [],
            'dynamic_occurrences' => $dynamicMatch['occurrences'] ?? [],
            'dynamic_pattern' => $dynamicMatch['pattern'] ?? null,
            'source' => $translation->source,
            'updated_at' => $translation->updated_at?->toIso8601String(),
            'values' => $values,
            'fallback' => collect($locales)->mapWithKeys(fn (string $locale): array => [$locale => [
                'mode' => app(TranslationFallbackRules::class)->mode($locale, $translation->group ?? 'json', $translation->key),
                'published_mode' => app(TranslationFallbackRules::class)->mode($locale, $translation->group ?? 'json', $translation->key, true),
                'selection' => app(TranslationFallbackRules::class)->selection($locale, 'key', $translation->group, $translation->key),
            ]])->all(),
            'file_values' => $translation->values->pluck('file_value', 'locale')->all(),
            'published_overrides' => $translation->values->pluck('published_override', 'locale')->all(),
            'blank_locales' => $translation->values->filter(fn ($value): bool => $this->eligibility->isBlank($value->value))->pluck('locale')->values()->all(),
            'draft_locales' => $translation->values->where('is_pending_publish', true)->where('is_approved', false)->pluck('locale')->all(),
            'pending_publish_locales' => $translation->values->filter(fn ($value): bool => $value->hasApprovedChange())->pluck('locale')->all(),
            'approved_locales' => $translation->values->where('is_approved', true)->pluck('locale')->all(),
            'values_count' => $translation->values->count(),
            'occurrences' => $translation->occurrences->reject(fn ($occurrence): bool => collect($dynamicMatch['occurrences'] ?? [])->contains(fn (array $possible): bool => $possible['file'] === $occurrence->file_path && ($possible['line'] ?? null) === $occurrence->line_number))->values()->map(function ($occurrence): array {
                return [
                    'id' => $occurrence->id,
                    'file_path' => $occurrence->file_path,
                    'line_number' => $occurrence->line_number,
                    'context_before' => $occurrence->context_before,
                    'context_after' => $occurrence->context_after,
                ];
            })->all(),
        ];
    }

    private function displayKey(VoxTranslation $translation): string
    {
        if ($translation->group === null || Str::startsWith($translation->group, 'json')) {
            return $translation->key;
        }

        return $translation->group.'.'.$translation->key;
    }

    private function freshnessStatus(VoxTranslation $translation, ?CarbonInterface $lastSyncAt): ?string
    {
        if ($lastSyncAt === null || $translation->is_orphan) {
            return null;
        }

        if ($translation->created_at?->greaterThanOrEqualTo($lastSyncAt)) {
            return 'new';
        }

        if ($translation->updated_at?->greaterThanOrEqualTo($lastSyncAt)) {
            return 'updated';
        }

        return null;
    }

    /**
     * @param  array<int, string>  $locales
     */
    private function hasMissingValues(VoxTranslation $translation, array $locales): bool
    {
        $values = $translation->values->keyBy('locale');

        $baseValue = $values->get($this->localeResolver->resolveBaseLocale($locales))?->value;
        if ($baseValue === null || $baseValue === '') {
            return false;
        }

        foreach ($locales as $locale) {
            if (app(TranslationFallbackRules::class)->usesDefault($locale, $translation->group, $translation->key)) {
                continue;
            }
            if ($this->eligibility->isMissing($values->get($locale)?->value)) {
                return true;
            }
        }

        return false;
    }
}
