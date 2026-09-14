<?php

namespace KeypointSolutions\LaravelVox\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use KeypointSolutions\LaravelVox\Support\VoxLocaleCatalog;
use KeypointSolutions\LaravelVox\Support\VoxLocaleResolver;
use KeypointSolutions\LaravelVox\Translation\LocaleProvisioner;
use Throwable;

class ProvisionLocaleController
{
    public function __invoke(
        Request $request,
        VoxLocaleResolver $localeResolver,
        VoxLocaleCatalog $localeCatalog,
        LocaleProvisioner $provisioner,
    ): RedirectResponse {
        $validated = $request->validate([
            'locale' => ['required', 'string', 'max:35', 'regex:/^[A-Za-z]{2,3}(?:[-_][A-Za-z0-9]{2,8})*$/D'],
            'auto_translate' => ['sometimes', 'boolean'],
        ], [
            'locale.regex' => 'Enter a locale code such as de, pt_BR, or zh_Hant.',
        ]);
        $locale = $localeResolver->normalizeLocaleCode($validated['locale']);

        if ($localeResolver->resolveLocale($locale) !== null) {
            throw ValidationException::withMessages([
                'locale' => "Locale [{$locale}] is already defined.",
            ]);
        }

        $autoTranslate = (bool) ($validated['auto_translate'] ?? false);

        if ($autoTranslate && config('vox.translate.driver', 'openai') === 'null') {
            throw ValidationException::withMessages([
                'auto_translate' => 'Configure an AI translation driver before using automatic translation.',
            ]);
        }

        try {
            $result = $provisioner->provision($locale, $autoTranslate);
        } catch (Throwable $exception) {
            report($exception);

            return redirect()->back()->withErrors(['locale' => $exception->getMessage()]);
        }

        $name = $localeCatalog->displayName($result->locale);

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
