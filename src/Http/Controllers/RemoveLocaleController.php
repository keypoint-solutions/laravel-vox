<?php

namespace KeypointSolutions\LaravelVox\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use KeypointSolutions\LaravelVox\Support\VoxLocaleCatalog;
use KeypointSolutions\LaravelVox\Translation\LocaleRemover;
use Throwable;

class RemoveLocaleController
{
    public function __invoke(Request $request, LocaleRemover $remover, VoxLocaleCatalog $catalog): RedirectResponse
    {
        $validated = $request->validate([
            'locale' => ['required', 'string', 'max:35', 'regex:/^[A-Za-z]{2,3}(?:[-_][A-Za-z0-9]{2,8})*$/D'],
        ]);
        try {
            $remover->remove($validated['locale']);
        } catch (Throwable $exception) {
            report($exception);

            return redirect()->back()->withErrors(['locale' => $exception->getMessage()]);
        }

        return Inertia::flash('success', 'Removed '.$catalog->displayName($validated['locale']).' ('.$validated['locale'].').')->back();
    }
}
