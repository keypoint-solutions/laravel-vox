<?php

namespace KeypointSolutions\LaravelVox\Support;

use Illuminate\Support\Facades\Schema;
use JsonException;
use KeypointSolutions\LaravelVox\Models\VoxSetting;

class VoxSettingsRepository
{
    /**
     * @var array<int, string>
     */
    private const EDITABLE_KEYS = [
        'translate_guidance',
        'translate_model',
        'protected_keys',
        'sync_enabled',
    ];

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return [
            'translate_guidance' => (string) $this->get(
                'translate_guidance',
                config('vox.translate.guidance', '')
            ),
            'protected_keys' => $this->protectedKeys(),
            'sync_enabled' => (bool) $this->get('sync_enabled', config('vox.sync.enabled', true)),
            'sync_key_set' => filled(config('vox.sync.key')),
        ];
    }

    /**
     * @return array<int, string>
     */
    public function protectedKeys(): array
    {
        $configured = config('vox.parse.protected_keys', []);
        $defaults = is_array($configured) ? $configured : [];
        $protectedKeys = $this->get('protected_keys', $defaults);

        if (! is_array($protectedKeys)) {
            return $defaults;
        }

        return array_values(array_unique(array_filter(
            array_map(
                static fn (mixed $key): string => is_string($key) ? trim($key) : '',
                $protectedKeys
            ),
            static fn (string $key): bool => $key !== ''
        )));
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    public function save(array $settings): bool
    {
        if (! $this->tableExists()) {
            return false;
        }

        foreach ($settings as $key => $value) {
            if (! in_array($key, self::EDITABLE_KEYS, true)) {
                continue;
            }

            VoxSetting::query()->updateOrCreate(
                ['key' => $key],
                ['value' => json_encode($value, JSON_THROW_ON_ERROR)]
            );
        }

        return true;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        if (! in_array($key, self::EDITABLE_KEYS, true) || ! $this->tableExists()) {
            return $default;
        }

        $value = VoxSetting::query()->where('key', $key)->value('value');

        if (! is_string($value)) {
            return $default;
        }

        try {
            return json_decode($value, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $default;
        }
    }

    private function tableExists(): bool
    {
        return Schema::connection(config('vox.database.connection', 'vox'))->hasTable('vox_settings');
    }
}
