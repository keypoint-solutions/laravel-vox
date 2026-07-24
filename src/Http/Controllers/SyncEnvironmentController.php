<?php

namespace KeypointSolutions\LaravelVox\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use KeypointSolutions\LaravelVox\Models\VoxEnvironment;

class SyncEnvironmentController
{
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate($this->rules(requireSecret: true));

        VoxEnvironment::query()->create($validated);

        return Inertia::flash('success', 'Environment added.')->back();
    }

    public function update(Request $request, VoxEnvironment $environment): RedirectResponse
    {
        $validated = $request->validate($this->rules(requireSecret: false));

        if (($validated['secret_key'] ?? '') === '') {
            unset($validated['secret_key']);
        }

        $environment->update($validated);

        return Inertia::flash('success', 'Environment updated.')->back();
    }

    public function destroy(VoxEnvironment $environment): RedirectResponse
    {
        $environment->delete();

        return Inertia::flash('success', 'Environment removed.')->back();
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function rules(bool $requireSecret): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'type' => ['required', Rule::in(['testing', 'staging', 'production', 'custom'])],
            'url' => ['required', 'url:http,https', 'max:2048'],
            'secret_key' => [$requireSecret ? 'required' : 'nullable', 'string', 'max:255'],
        ];
    }
}
