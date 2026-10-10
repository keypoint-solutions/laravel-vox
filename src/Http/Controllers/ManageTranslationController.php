<?php

namespace KeypointSolutions\LaravelVox\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use KeypointSolutions\LaravelVox\Models\VoxTranslation;
use KeypointSolutions\LaravelVox\Models\VoxTranslationValue;
use KeypointSolutions\LaravelVox\Support\VoxAuditLogger;
use KeypointSolutions\LaravelVox\Support\VoxConfig;
use KeypointSolutions\LaravelVox\Translation\Locales\VoxLocaleResolver;
use KeypointSolutions\LaravelVox\Translation\Publishing\VoxFrontendManifest;
use KeypointSolutions\LaravelVox\Translation\Scanning\VoxDynamicKeyRegistry;
use KeypointSolutions\LaravelVox\Translation\TranslationKey;

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

        [$locales, $baseLocale] = $this->localeResolver->resolveLocalesWithBase();
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

        $translation = VoxConfig::connection()
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

                    if (! is_string($value) || trim($value) === '') {
                        continue;
                    }

                    VoxTranslationValue::query()->create([
                        'translation_id' => $translation->id,
                        'locale' => $locale,
                        'value' => $value,
                        'is_pending_publish' => true,
                        'is_approved' => false,
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

    public function update(
        Request $request,
        VoxTranslation $translation,
        VoxAuditLogger $auditLogger,
    ): RedirectResponse {
        abort_if($translation->is_pending_delete, 422, 'Cancel deletion before editing this key.');
        $validated = $request->validate([
            'values' => ['required', 'array'],
            'values.*' => ['nullable', 'string'],
        ]);

        [$locales] = $this->localeResolver->resolveLocalesWithBase();

        foreach ($validated['values'] as $locale => $value) {
            if (! is_string($locale) || ! in_array($locale, $locales, true)) {
                continue;
            }

            $value = is_string($value) ? $value : '';
            $translationValue = VoxTranslationValue::query()->firstOrNew([
                'translation_id' => $translation->id,
                'locale' => $locale,
            ]);

            if (! $translationValue->exists && trim($value) === '') {
                continue;
            }

            $translationValue->saveDraft($value);
        }

        $translation->touch();
        $auditLogger->record('translation-updated', [
            'translation_id' => $translation->id,
            'locales' => array_values(array_intersect($locales, array_keys($validated['values']))),
        ]);

        return Inertia::flash('success', 'Translations saved.')->back();
    }

    public function toggleApproval(VoxTranslation $translation, VoxAuditLogger $auditLogger): RedirectResponse
    {
        abort_if($translation->is_pending_delete, 422, 'Cancel deletion before editing this key.');
        $status = $translation->status === 'approved' ? 'pending' : 'approved';

        $translation->timestamps = false;
        $translation->values()->update(['is_approved' => $status === 'approved']);
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
            ->where('is_pending_delete', false)
            ->whereIn('id', $validated['ids'])
            ->pluck('id')
            ->all();

        VoxTranslation::query()
            ->whereIn('id', $ids)
            ->toBase()
            ->update(['status' => $status]);

        VoxTranslationValue::query()->whereIn('translation_id', $ids)->update(['is_approved' => $status === 'approved']);

        $action = $status === 'approved' ? 'translations-bulk-approved' : 'translations-bulk-reopened';
        $auditLogger->record($action, [
            'translation_ids' => $ids,
            'count' => count($ids),
        ]);

        $verb = $status === 'approved' ? 'Approved' : 'Returned to review';
        $noun = count($ids) === 1 ? 'translation' : 'translations';

        return Inertia::flash('success', "{$verb} ".count($ids)." {$noun}.")->back();
    }
}
