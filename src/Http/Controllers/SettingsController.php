<?php

namespace KeypointSolutions\LaravelVox\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use KeypointSolutions\LaravelVox\Support\VoxSettingsRepository;

class SettingsController
{
    public function __construct(private VoxSettingsRepository $settings) {}

    public function index(): Response
    {
        return Inertia::render('Settings', [
            'settings' => $this->settings->all(),
            'drivers' => $this->getAvailableDrivers(),
        ]);
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    private function getAvailableDrivers(): array
    {
        return [
            ['value' => 'openai', 'label' => 'OpenAI'],
        ];
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'translate_driver' => ['required', 'string', 'in:openai'],
            'translate_prompt' => ['required', 'string', 'max:2000'],
            'sync_enabled' => ['required', 'boolean'],
            'openai_api_key' => ['nullable', 'string', 'max:255'],
            'openai_model' => ['required', 'string', 'max:100'],
            'openai_endpoint' => ['required', 'url', 'max:500'],
            'openai_temperature' => ['required', 'numeric', 'min:0', 'max:2'],
        ]);

        $settings = [
            'translate_driver' => $validated['translate_driver'],
            'translate_prompt' => $validated['translate_prompt'],
            'sync_enabled' => $validated['sync_enabled'],
        ];

        if ($validated['translate_driver'] === 'openai') {
            // Only update API key if a new one is provided
            if (filled($validated['openai_api_key'] ?? null)) {
                $settings['openai_api_key'] = $validated['openai_api_key'];
            }
            $settings['openai_model'] = $validated['openai_model'] ?? 'gpt-4o-mini';
            $settings['openai_endpoint'] = $validated['openai_endpoint'] ?? 'https://api.openai.com/v1/chat/completions';
            $settings['openai_temperature'] = (float) ($validated['openai_temperature'] ?? 0.2);
        }

        $saved = $this->settings->save($settings);

        if (! $saved) {
            return redirect()->back()->withErrors(['general' => 'Failed to save settings. Make sure the .env file exists and is writable.']);
        }

        return redirect()->back()->with('success', 'Settings saved successfully.');
    }
}
