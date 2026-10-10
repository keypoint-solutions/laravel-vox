<?php

namespace KeypointSolutions\LaravelVox\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use KeypointSolutions\LaravelVox\Ai\AiAvailability;
use KeypointSolutions\LaravelVox\Translation\Locales\LocaleProvisioner;
use KeypointSolutions\LaravelVox\Translation\Locales\VoxLocaleCatalog;
use KeypointSolutions\LaravelVox\Translation\Locales\VoxLocaleResolver;
use Throwable;

class ProvisionLocaleController
{
    public function __invoke(
        Request $request,
        VoxLocaleResolver $localeResolver,
        VoxLocaleCatalog $localeCatalog,
        LocaleProvisioner $provisioner,
        AiAvailability $ai,
    ): RedirectResponse {
        $validated = $request->validate([
            'locale' => ['required', 'string', 'max:35', 'regex:/^[A-Za-z]{2,3}(?:[-_][A-Za-z0-9]{2,8})*$/D'],
            'auto_translate' => ['sometimes', 'boolean'],
            'use_default' => ['sometimes', 'boolean'],
        ], [
            'locale.regex' => 'Enter a locale code such as de, pt_BR, or zh_Hant.',
        ]);
        $locale = $localeResolver->normalizeLocaleCode($validated['locale']);

        if ($localeResolver->resolveLocale($locale) !== null) {
            throw ValidationException::withMessages([
                'locale' => "Locale [{$locale}] is already defined.",
            ]);
        }

        $useDefault = (bool) ($validated['use_default'] ?? false);
        $autoTranslate = ! $useDefault && (bool) ($validated['auto_translate'] ?? false);

        if ($autoTranslate && ! $ai->available()) {
            throw ValidationException::withMessages([
                'auto_translate' => 'Configure an AI translation driver before using automatic translation.',
            ]);
        }

        try {
            $result = $provisioner->provision($locale, $autoTranslate, $useDefault);
        } catch (Throwable $exception) {
            report($exception);

            return redirect()->back()->withErrors(['locale' => $exception->getMessage()]);
        }

        $name = $localeCatalog->displayName($result->locale);

        if ($useDefault) {
            return Inertia::flash('success', "Added {$name} ({$result->locale}) using default language wording. Publish to create its language files.")->back();
        }

        if ($autoTranslate && $result->translationFailure !== null) {
            return Inertia::flash(
                'success',
                "Added {$name} ({$result->locale}) and AI translated {$result->translatedValues} of {$result->values} values, "
                    ."then stopped: {$result->translationFailure} The remaining values are marked for translation."
            )->back();
        }

        if ($autoTranslate) {
            return Inertia::flash(
                'success',
                "Added {$name} ({$result->locale}) and AI translated {$result->translatedValues} values. "
                    .'The new file values are live and available in Manage.'
            )->back();
        }

        return Inertia::flash(
            'success',
            "Added {$name} ({$result->locale}) with {$result->values} values marked for translation."
        )->back();
    }
}
