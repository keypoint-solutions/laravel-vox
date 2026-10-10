<?php

namespace KeypointSolutions\LaravelVox\Ai;

use Illuminate\Support\Facades\Cache;
use KeypointSolutions\LaravelVox\Support\VoxConfig;
use KeypointSolutions\LaravelVox\Support\VoxSettingsRepository;
use Throwable;

class ClaudeModelDiscovery implements AiModelDiscovery
{
    public function __construct(
        private ClaudeAiClient $client,
        private ClaudeModelCatalog $catalog,
        private VoxSettingsRepository $settings,
    ) {}

    /**
     * @return array{
     *     provider: array{id: string, label: string, credentials_set: bool},
     *     model: string,
     *     status: string,
     *     message: string,
     *     checked_at: string|null,
     *     models: array<int, array{value: string, label: string, available: bool}>
     * }
     */
    public function discover(bool $refresh = false): array
    {
        $configuredModel = $this->catalog->resolve((string) $this->settings->get(
            'translate_model',
            VoxConfig::translateModel()
        ));
        $apiKey = config('vox.translate.providers.anthropic.api_key');
        $provider = [
            'id' => 'claude',
            'label' => 'Claude',
            'credentials_set' => is_string($apiKey) && $apiKey !== '',
        ];

        if (! is_string($apiKey) || $apiKey === '') {
            return [
                'provider' => $provider,
                'model' => $configuredModel,
                'status' => 'missing_key',
                'message' => 'Configure the provider credentials in the application environment to retrieve available models.',
                'checked_at' => null,
                'models' => $this->catalog->options([], $configuredModel),
            ];
        }

        $cacheKey = 'vox:claude-models:'.hash('sha256', $apiKey);

        if ($refresh) {
            Cache::forget($cacheKey);
        }

        try {
            /** @var array{ids: array<string, string>, checked_at: string} $result */
            $result = Cache::remember($cacheKey, now()->addMinutes(15), fn (): array => [
                'ids' => $this->client->listModels(),
                'checked_at' => now()->toIso8601String(),
            ]);

            $options = $this->catalog->options($result['ids'], $configuredModel);

            return [
                'provider' => $provider,
                'model' => $configuredModel,
                'status' => 'connected',
                'message' => count(array_filter($options, fn (array $option): bool => $option['available'])).' supported text models are available to this provider account.',
                'checked_at' => $result['checked_at'],
                'models' => $options,
            ];
        } catch (Throwable $exception) {
            report($exception);

            return [
                'provider' => $provider,
                'model' => $configuredModel,
                'status' => 'error',
                'message' => 'Model discovery failed. Check the provider credentials, account access, and network connection.',
                'checked_at' => null,
                'models' => $this->catalog->options([], $configuredModel),
            ];
        }
    }
}
