<?php

namespace KeypointSolutions\LaravelVox\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use KeypointSolutions\LaravelVox\Models\VoxEnvironment;
use KeypointSolutions\LaravelVox\Models\VoxRemoteTranslation;

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

        $environment->getConnection()->transaction(function () use ($environment, $validated): void {
            $locked = VoxEnvironment::query()->lockForUpdate()->findOrFail($environment->id);

            if ($locked->url !== $validated['url']) {
                VoxRemoteTranslation::query()->where('environment_id', $locked->id)->delete();
                $locked->sync_revision++;
                $locked->last_pulled_at = null;
            }

            $locked->fill($validated)->save();
        });

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
