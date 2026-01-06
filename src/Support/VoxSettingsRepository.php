<?php

namespace KeypointSolutions\LaravelVox\Support;

use Illuminate\Support\Facades\File;

class VoxSettingsRepository
{
    /**
     * Mapping of settings keys to .env variable names.
     *
     * @var array<string, string>
     */
    private const ENV_MAPPING = [
        'translate_driver' => 'VOX_TRANSLATE_DRIVER',
        'translate_prompt' => 'VOX_TRANSLATE_PROMPT',
        'sync_enabled' => 'VOX_SYNC_ENABLED',
        'openai_api_key' => 'OPENAI_API_KEY',
        'openai_model' => 'VOX_OPENAI_MODEL',
        'openai_endpoint' => 'VOX_OPENAI_ENDPOINT',
        'openai_temperature' => 'VOX_OPENAI_TEMPERATURE',
    ];

    /**
     * Get all settings from config.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return [
            'translate_driver' => config('vox.translate.driver', 'openai'),
            'translate_prompt' => config('vox.translate.prompt', ''),
            'sync_enabled' => config('vox.sync.enabled', true),
            'sync_key' => config('vox.sync.key', ''),
            'openai_api_key' => $this->maskApiKey(config('vox.translate.openai.api_key', '')),
            'openai_api_key_set' => ! empty(config('vox.translate.openai.api_key')),
            'openai_model' => config('vox.translate.openai.model', 'gpt-4o-mini'),
            'openai_endpoint' => config('vox.translate.openai.endpoint', 'https://api.openai.com/v1/chat/completions'),
            'openai_temperature' => (float) config('vox.translate.openai.temperature', 0.2),
        ];
    }

    /**
     * Mask API key for display.
     */
    private function maskApiKey(?string $key): string
    {
        if ($key === null || $key === '') {
            return '';
        }

        if (strlen($key) <= 8) {
            return str_repeat('•', strlen($key));
        }

        return substr($key, 0, 4).str_repeat('•', strlen($key) - 8).substr($key, -4);
    }

    /**
     * Save settings to the .env file.
     *
     * @param  array<string, mixed>  $settings
     */
    public function save(array $settings): bool
    {
        $envPath = base_path('.env');

        if (! File::exists($envPath)) {
            return false;
        }

        $envContent = File::get($envPath);

        foreach ($settings as $key => $value) {
            if (! isset(self::ENV_MAPPING[$key])) {
                continue;
            }

            $envKey = self::ENV_MAPPING[$key];
            $envValue = $this->formatEnvValue($value);

            $envContent = $this->setEnvValue($envContent, $envKey, $envValue);
        }

        return File::put($envPath, $envContent) !== false;
    }

    /**
     * Get a specific setting value.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return config("vox.{$key}", $default);
    }

    /**
     * Format a value for .env file (handle quoting).
     */
    private function formatEnvValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_numeric($value)) {
            return (string) $value;
        }

        $stringValue = (string) $value;

        if ($stringValue === '' || preg_match('/[\s#"\'\\\\]/', $stringValue)) {
            return '"'.addcslashes($stringValue, '"\\').'"';
        }

        return $stringValue;
    }

    /**
     * Set or update an environment variable in the .env content.
     */
    private function setEnvValue(string $envContent, string $key, string $value): string
    {
        $pattern = '/^'.preg_quote($key, '/').'=.*/m';

        if (preg_match($pattern, $envContent)) {
            return preg_replace($pattern, "{$key}={$value}", $envContent);
        }

        return rtrim($envContent)."\n{$key}={$value}\n";
    }
}
