<?php

namespace KeypointSolutions\LaravelVox\Translation\Drivers;

use Illuminate\Support\Facades\Http;
use KeypointSolutions\LaravelVox\Translation\TranslationPromptBuilder;

class OpenAiTranslationDriver implements TranslationDriver
{
    public function __construct(private TranslationPromptBuilder $promptBuilder)
    {
    }

    /**
     * @param array<string, mixed> $context
     */
    public function translate(string $text, string $sourceLocale, string $targetLocale, array $context = []): string
    {
        $apiKey = config('vox.translate.openai.api_key');

        if (! is_string($apiKey) || $apiKey === '') {
            throw new \RuntimeException('OpenAI API key is not configured.');
        }

        $response = Http::withToken($apiKey)
            ->post(config('vox.translate.openai.endpoint'), [
                'model' => config('vox.translate.openai.model'),
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => $this->promptBuilder->build($text, $sourceLocale, $targetLocale, $context),
                    ],
                    [
                        'role' => 'user',
                        'content' => $text,
                    ],
                ],
                'temperature' => (float) config('vox.translate.openai.temperature', 0.2),
            ]);

        $payload = $response->json();
        $content = $payload['choices'][0]['message']['content'] ?? null;

        if (! is_string($content) || $content === '') {
            throw new \RuntimeException('OpenAI response did not contain a translation.');
        }

        return trim($content);
    }
}
