<?php

namespace KeypointSolutions\LaravelVox\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use KeypointSolutions\LaravelVox\Support\AiModelDiscovery;
use KeypointSolutions\LaravelVox\Support\VoxSettingsRepository;

class SettingsController
{
    public function __construct(
        private VoxSettingsRepository $settings,
        private AiModelDiscovery $models,
    ) {}

    public function index(): Response
    {
        return Inertia::render('Settings', [
            'settings' => $this->settings->all(),
            'ai' => $this->models->discover(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $section = $request->validate([
            'section' => ['required', 'string', Rule::in(['ai', 'sync'])],
        ])['section'];

        if ($section === 'ai') {
            return $this->updateAiSettings($request);
        }

        return $this->updateSyncSettings($request);
    }

    public function refreshModels(): RedirectResponse
    {
        $result = $this->models->discover(refresh: true);

        if ($result['status'] !== 'connected') {
            return redirect()->back()->withErrors(['models' => $result['message']]);
        }

        return Inertia::flash('success', 'AI provider connection verified and available models refreshed.')->back();
    }

    private function updateAiSettings(Request $request): RedirectResponse
    {
        $availableModels = collect($this->models->discover()['models'])
            ->pluck('value')
            ->filter(fn (mixed $model): bool => is_string($model))
            ->values()
            ->all();

        $validated = $request->validate([
            'model' => ['required', 'string', 'max:100', Rule::in($availableModels)],
            'translate_guidance' => ['nullable', 'string', 'max:2000'],
        ]);

        $saved = $this->settings->save([
            'translate_model' => $validated['model'],
            'translate_guidance' => $validated['translate_guidance'] ?? '',
        ]);

        if (! $saved) {
            return redirect()->back()->withErrors([
                'general' => 'Settings storage is unavailable. Run the Laravel Vox migrations and try again.',
            ]);
        }

        return Inertia::flash('success', 'AI translation settings saved.')->back();
    }

    private function updateSyncSettings(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'sync_enabled' => ['required', 'boolean'],
        ]);

        if (! $this->settings->save(['sync_enabled' => $validated['sync_enabled']])) {
            return redirect()->back()->withErrors([
                'general' => 'Settings storage is unavailable. Run the Laravel Vox migrations and try again.',
            ]);
        }

        return Inertia::flash('success', 'Remote sync settings saved.')->back();
    }
}
