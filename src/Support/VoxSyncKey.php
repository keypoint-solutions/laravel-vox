<?php

namespace KeypointSolutions\LaravelVox\Support;

class VoxSyncKey
{
    public function __construct(private VoxSettingsRepository $settings)
    {
    }

    public function get(): ?string
    {
        $configured = config('vox.sync.key');

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        return null;
    }

    public function store(string $value): bool
    {
        return $this->settings->save(['sync_key' => $value]);
    }
}
