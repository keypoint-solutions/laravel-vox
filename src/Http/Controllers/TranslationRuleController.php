<?php

namespace KeypointSolutions\LaravelVox\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use KeypointSolutions\LaravelVox\Models\VoxTranslation;
use KeypointSolutions\LaravelVox\Support\VoxAuditLogger;
use KeypointSolutions\LaravelVox\Support\VoxLocaleResolver;
use KeypointSolutions\LaravelVox\Translation\TranslationFallbackRules;

class TranslationRuleController
{
    public function __invoke(Request $request, TranslationFallbackRules $rules, VoxLocaleResolver $locales, VoxAuditLogger $audit): RedirectResponse
    {
        $data = $request->validate([
            'locale' => ['required', Rule::in(array_diff($locales->resolveLocales(), [$locales->resolveBaseLocale([])]))],
            'scope' => ['required', Rule::in(['locale', 'group', 'key'])],
            'mode' => ['required', Rule::in(['inherit', 'translated', 'default'])],
            'group' => ['nullable', 'string', 'max:255', 'required_if:scope,group'],
            'translation_id' => ['nullable', 'integer', 'required_if:scope,key'],
        ]);
        $translation = $data['scope'] === 'key' ? VoxTranslation::query()->findOrFail($data['translation_id']) : null;
        abort_if($translation?->is_pending_delete, 422, 'Restore this key before changing its fallback rule.');
        $group = $translation !== null ? ($translation->group ?? 'json') : ($data['group'] ?? null);
        $rules->save($data['locale'], $data['scope'], $data['mode'], $group, $translation?->key);
        $audit->record('fallback-rule', ['locale' => $data['locale'], 'scope' => $data['scope'], 'mode' => $data['mode'], 'group' => $group, 'translation_id' => $translation?->id]);

        return Inertia::flash('success', 'Fallback choice saved. Publish to apply it to the application.')->back();
    }
}
