<?php

namespace KeypointSolutions\LaravelVox\Support;

use KeypointSolutions\LaravelVox\Models\VoxSetting;

class VoxSyncKey
{
    public function get(): ?string
    {
        $configured = config('vox.sync.key');

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        return VoxSetting::query()->where('key', 'sync_key')->value('value');
    }

    public function store(string $value): void
    {
        VoxSetting::query()->updateOrCreate(
            ['key' => 'sync_key'],
            ['value' => $value]
        );
    }
}
