<?php

namespace KeypointSolutions\LaravelVox\Ai;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class OpenAiClient
{
    public const RESPONSES_ENDPOINT = 'https://api.openai.com/v1/responses';

    public const MODELS_ENDPOINT = 'https://api.openai.com/v1/models';

    public function __construct(private OpenAiModelCatalog $catalog) {}

    /**
     * @return array<string, mixed>
     */
    public function createResponse(string $model, string $instructions, string $input): array
    {
        $parameters = [
            'model' => $model,
            'instructions' => $instructions,
            'input' => $input,
            'store' => false,
        ];

        $reasoningEffort = $this->catalog->translationReasoningEffort($model);

        if ($reasoningEffort !== null) {
            $parameters['reasoning'] = ['effort' => $reasoningEffort];
        }

        $response = Http::acceptJson()
            ->asJson()
            ->withToken($this->apiKey())
            ->timeout(60)
            ->post(self::RESPONSES_ENDPOINT, $parameters);

        if (! $response->successful()) {
            throw AiProviderException::fromResponse('OpenAI', $response);
        }

        $payload = $response->json();

        if (! is_array($payload)) {
            throw new RuntimeException('OpenAI returned an invalid JSON response.');
        }

        return $payload;
    }

    /**
     * @return array<int, string>
     */
    public function listModelIds(): array
    {
        $response = Http::acceptJson()
            ->withToken($this->apiKey())
            ->timeout(15)
            ->get(self::MODELS_ENDPOINT);

        if (! $response->successful()) {
            throw AiProviderException::fromResponse('OpenAI', $response);
        }

        return collect($response->json('data', []))
            ->pluck('id')
            ->filter(fn (mixed $model): bool => is_string($model) && $model !== '')
            ->values()
            ->all();
    }

    private function apiKey(): string
    {
        $apiKey = config('vox.translate.providers.openai.api_key');

        if (! is_string($apiKey) || $apiKey === '') {
            throw new RuntimeException('OpenAI API key is not configured.');
        }

        return $apiKey;
    }
}
