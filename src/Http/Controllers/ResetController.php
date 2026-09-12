<?php

namespace KeypointSolutions\LaravelVox\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use KeypointSolutions\LaravelVox\Translation\TranslationResetter;

class ResetController
{
    public function __invoke(Request $request, TranslationResetter $resetter): RedirectResponse
    {
        $scope = $request->validate([
            'scope' => ['required', 'string', Rule::in(TranslationResetter::SCOPES)],
        ])['scope'];

        $request->validate([
            'confirmation' => ['required', 'string', Rule::in([TranslationResetter::confirmation($scope)])],
        ], [
            'confirmation.required' => 'Type '.TranslationResetter::confirmation($scope).' to confirm this destructive action.',
            'confirmation.in' => 'Type '.TranslationResetter::confirmation($scope).' exactly to confirm this destructive action.',
        ]);

        $resetter->reset($scope);

        return Inertia::flash('success', 'Vox reset complete. Published language files were not changed.')->back();
    }
}
