<?php

namespace KeypointSolutions\LaravelVox\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use KeypointSolutions\LaravelVox\Translation\RemoteReconciliation;

class ReconcileRemoteTranslationsController
{
    public function __invoke(Request $request, RemoteReconciliation $reconciliation): RedirectResponse
    {
        $validated = $request->validate([
            'action' => ['required', Rule::in(['accept', 'keep', 'edit'])],
            'publish' => ['sometimes', 'boolean'],
            'all_matching' => ['sometimes', 'boolean'],
            'selection_token' => ['nullable', 'string', 'size:64'],
            'entries' => ['sometimes', 'array', 'max:1000'],
            'entries.*.id' => ['required', 'integer', 'distinct'],
            'entries.*.token' => ['required', 'string', 'size:64'],
            'value' => ['sometimes', 'nullable', 'string', 'max:1000000'],
            'filters' => ['sometimes', 'array:environment_id,state,locale,search'],
            'filters.environment_id' => ['nullable', 'integer'],
            'filters.state' => ['nullable', Rule::in(['all', 'review', 'incoming', 'outgoing', 'conflict', 'reconciled', 'unavailable'])],
            'filters.locale' => ['nullable', 'string', 'max:255'],
            'filters.search' => ['nullable', 'string', 'max:255'],
        ]);

        if ($validated['action'] === 'edit' && array_key_exists('value', $validated)) {
            $validated['value'] ??= '';
        }

        $count = $reconciliation->resolve($validated);
        $message = $validated['action'] === 'keep'
            ? "Kept local values for {$count} incoming changes."
            : "Accepted and approved {$count} values.".(! empty($validated['publish']) ? ' Published the selected eligible values.' : ' Ready to publish.');

        return Inertia::flash('success', $message)->back();
    }
}
