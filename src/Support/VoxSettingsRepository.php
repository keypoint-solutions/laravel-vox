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
        'dynamic_key_patterns',
        'provisioned_locales',
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
            'dynamic_key_patterns' => $this->editableDynamicKeyPatterns(),
            'configured_dynamic_key_patterns' => $this->configuredDynamicKeyPatterns(),
            'sync_enabled' => (bool) $this->get('sync_enabled', config('vox.sync.enabled', true)),
            'sync_key_set' => filled(config('vox.sync.key')),
        ];
    }

    /**
     * @return array<int, string>
     */
    public function dynamicKeyPatterns(): array
    {
        return array_values(array_unique(array_merge(
            $this->configuredDynamicKeyPatterns(),
            $this->editableDynamicKeyPatterns()
        )));
    }

    /**
     * @return array<int, string>
     */
    public function configuredDynamicKeyPatterns(): array
    {
        $configured = config('vox.retained_keys', []);
        $bindings = config('vox.dynamic_keys.bindings', []);

        return $this->normalizePatterns(array_merge(
            is_array($configured) ? $configured : [$configured],
            is_array($bindings) ? array_keys($bindings) : []
        ));
    }

    /**
     * @return array<int, string>
     */
    public function editableDynamicKeyPatterns(): array
    {
        $patterns = $this->get('dynamic_key_patterns');

        return $this->normalizePatterns(is_array($patterns) ? $patterns : []);
    }

    /**
     * @return array<int, string>
     */
    public function provisionedLocales(): array
    {
        $locales = $this->get('provisioned_locales', []);

        if (! is_array($locales)) {
            return [];
        }

        return array_values(array_unique(array_filter(
            array_map(
                static fn (mixed $locale): string => is_string($locale) ? trim($locale) : '',
                $locales
            ),
            static fn (string $locale): bool => $locale !== ''
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

    /**
     * @param  array<int, mixed>  $patterns
     * @return array<int, string>
     */
    private function normalizePatterns(array $patterns): array
    {
        return array_values(array_unique(array_filter(
            array_map(
                static fn (mixed $pattern): string => VoxDynamicKeyRegistry::normalizePattern($pattern),
                $patterns
            ),
            static fn (string $pattern): bool => $pattern !== ''
        )));
    }
}
