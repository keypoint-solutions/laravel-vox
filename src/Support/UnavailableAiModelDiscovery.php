<?php

namespace KeypointSolutions\LaravelVox\Support;

use Illuminate\Support\Str;

class UnavailableAiModelDiscovery implements AiModelDiscovery
{
    /**
     * @return array{
     *     provider: array{id: string, label: string, credentials_set: bool},
     *     model: string,
     *     status: string,
     *     message: string,
     *     checked_at: null,
     *     models: array<int, array{value: string, label: string, available: bool}>
     * }
     */
    public function discover(bool $refresh = false): array
    {
        $driver = (string) config('vox.translate.driver', 'null');
        $model = (string) config('vox.translate.model', '');

        return [
            'provider' => [
                'id' => $driver,
                'label' => Str::headline($driver),
                'credentials_set' => false,
            ],
            'model' => $model,
            'status' => 'unavailable',
            'message' => 'The active translation driver does not provide model discovery.',
            'checked_at' => null,
            'models' => $model === '' ? [] : [[
                'value' => $model,
                'label' => $model.' · Configured',
                'available' => false,
            ]],
        ];
    }
}
