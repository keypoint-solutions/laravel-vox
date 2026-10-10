<?php

namespace KeypointSolutions\LaravelVox\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use KeypointSolutions\LaravelVox\Ai\AiModelDiscovery;
use KeypointSolutions\LaravelVox\Support\VoxSettingsRepository;
use KeypointSolutions\LaravelVox\Translation\Scanning\VoxDynamicKeyRegistry;

class SettingsController
{
    public function __construct(
        private VoxSettingsRepository $settings,
        private AiModelDiscovery $models,
        private VoxDynamicKeyRegistry $dynamicKeys,
    ) {}

    public function index(): Response
    {
        return Inertia::render('Settings', [
            'settings' => $this->settings->all(),
            'dynamicPatterns' => $this->dynamicKeys->entries(),
            'ai' => $this->models->discover(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $section = $request->validate([
            'section' => ['required', 'string', Rule::in(['ai', 'dynamic_keys', 'sync', 'checkpoints'])],
        ])['section'];

        if ($section === 'checkpoints') {
            $data = $request->validate(['checkpoint_limit' => ['required', 'integer', 'min:1', 'max:1000']]);
            if (! $this->settings->save(['checkpoint_limit' => (int) $data['checkpoint_limit']])) {
                return redirect()->back()->withErrors(['checkpoint_limit' => 'Settings storage is unavailable.']);
            }

            return Inertia::flash('success', 'Checkpoint retention saved. Older checkpoints will be removed by daily cleanup or the next translation change.')->back();
        }

        if ($section === 'ai') {
            return $this->updateAiSettings($request);
        }

        if ($section === 'dynamic_keys') {
            return $this->updateDynamicKeySettings($request);
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

    private function updateDynamicKeySettings(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'dynamic_key_patterns' => ['nullable', 'string', 'max:10000'],
        ]);

        $patterns = collect(preg_split('/\R/', $validated['dynamic_key_patterns'] ?? '') ?: [])
            ->map(fn (string $pattern): string => VoxDynamicKeyRegistry::normalizePattern($pattern))
            ->filter()
            ->unique()
            ->values();

        if ($patterns->count() > 100 || $patterns->contains(
            fn (string $pattern): bool => mb_strlen($pattern) > 255
        )) {
            return redirect()->back()->withErrors([
                'dynamic_key_patterns' => 'Use at most 100 dynamic key patterns, with no entry longer than 255 characters.',
            ]);
        }

        if (! $this->settings->save(['dynamic_key_patterns' => $patterns->all()])) {
            return redirect()->back()->withErrors([
                'general' => 'Settings storage is unavailable. Run the Laravel Vox migrations and try again.',
            ]);
        }

        return Inertia::flash('success', 'Dynamic key patterns saved.')->back();
    }
}
