<?php

namespace KeypointSolutions\LaravelVox\Ai;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class ClaudeAiClient
{
    public const MESSAGES_ENDPOINT = 'https://api.anthropic.com/v1/messages';

    public const MODELS_ENDPOINT = 'https://api.anthropic.com/v1/models';

    private const API_VERSION = '2023-06-01';

    /**
     * Room for the translation plus any thinking, which counts toward the same limit.
     */
    private const MAX_TOKENS = 16000;

    public function __construct(private ClaudeModelCatalog $catalog) {}

    /**
     * Send one instruction and input, and return the text of Claude's reply.
     */
    public function createMessage(string $model, string $instructions, string $input): string
    {
        $parameters = [
            'model' => $model,
            'max_tokens' => self::MAX_TOKENS,
            'system' => $instructions,
            'messages' => [
                ['role' => 'user', 'content' => $input],
            ],
        ];

        $effort = $this->catalog->translationEffort($model);

        if ($effort !== null) {
            $parameters['output_config'] = ['effort' => $effort];
        }

        $response = $this->request()->timeout(60)->post(self::MESSAGES_ENDPOINT, $parameters);

        if (! $response->successful()) {
            throw AiProviderException::fromResponse('Claude', $response);
        }

        $payload = $response->json();

        if (! is_array($payload)) {
            throw new RuntimeException('Claude returned an invalid JSON response.');
        }

        $stopReason = $payload['stop_reason'] ?? null;

        if ($stopReason === 'refusal') {
            throw new RuntimeException('Claude declined to process this text.');
        }

        if ($stopReason === 'max_tokens') {
            throw new RuntimeException('Claude stopped before finishing: the text is too long for one request.');
        }

        $text = '';

        foreach (is_array($payload['content'] ?? null) ? $payload['content'] : [] as $block) {
            if (is_array($block) && ($block['type'] ?? null) === 'text' && is_string($block['text'] ?? null)) {
                $text .= $block['text'];
            }
        }

        return $text;
    }

    /**
     * @return array<string, string> Display names keyed by model ID.
     */
    public function listModels(): array
    {
        $models = [];
        $afterId = null;

        do {
            $response = $this->request()->timeout(15)->get(self::MODELS_ENDPOINT, array_filter([
                'limit' => 100,
                'after_id' => $afterId,
            ]));

            if (! $response->successful()) {
                throw AiProviderException::fromResponse('Claude', $response);
            }

            foreach ((array) $response->json('data', []) as $model) {
                if (is_array($model) && is_string($model['id'] ?? null) && $model['id'] !== '') {
                    $models[$model['id']] = is_string($model['display_name'] ?? null) ? $model['display_name'] : $model['id'];
                }
            }

            $afterId = $response->json('has_more') === true ? $response->json('last_id') : null;
        } while (is_string($afterId) && $afterId !== '');

        return $models;
    }

    private function request(): PendingRequest
    {
        return Http::acceptJson()->asJson()->withHeaders([
            'x-api-key' => $this->apiKey(),
            'anthropic-version' => self::API_VERSION,
        ]);
    }

    private function apiKey(): string
    {
        $apiKey = config('vox.translate.providers.anthropic.api_key');

        if (! is_string($apiKey) || $apiKey === '') {
            throw new RuntimeException('Anthropic API key is not configured.');
        }

        return $apiKey;
    }
}
