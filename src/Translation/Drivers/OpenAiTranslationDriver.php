<?php

namespace KeypointSolutions\LaravelVox\Translation\Drivers;

use Illuminate\Support\Facades\Http;
use KeypointSolutions\LaravelVox\Translation\LaravelPlaceholderProtector;
use KeypointSolutions\LaravelVox\Translation\TranslationPromptBuilder;

class OpenAiTranslationDriver implements TranslationDriver
{
    public function __construct(
        private TranslationPromptBuilder $promptBuilder,
        private LaravelPlaceholderProtector $placeholderProtector,
    ) {}

    /**
     * @param  array<string, mixed>  $context
     */
    public function translate(string $text, string $sourceLocale, string $targetLocale, array $context = []): string
    {
        $apiKey = config('vox.translate.openai.api_key');

        if (! is_string($apiKey) || $apiKey === '') {
            throw new \RuntimeException('OpenAI API key is not configured.');
        }

        [$protectedText, $placeholders] = $this->placeholderProtector->protect($text);
        $systemPrompt = $this->promptBuilder->build(
            $protectedText,
            $sourceLocale,
            $targetLocale,
            $context,
        );

        if ($placeholders !== []) {
            $systemPrompt .= "\nLaravel placeholders are represented by __LARAVEL_PLACEHOLDER_n__ tokens. Preserve every token exactly.";
        }

        $response = Http::withToken($apiKey)
            ->post(config('vox.translate.openai.endpoint'), [
                'model' => config('vox.translate.openai.model'),
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => $systemPrompt,
                    ],
                    [
                        'role' => 'user',
                        'content' => $protectedText,
                    ],
                ],
                'temperature' => (float) config('vox.translate.openai.temperature', 0.2),
            ]);

        $response->throw();

        $payload = $response->json();
        $content = $payload['choices'][0]['message']['content'] ?? null;

        if (! is_string($content) || trim($content) === '') {
            throw new \RuntimeException('OpenAI response did not contain a translation.');
        }

        return $this->placeholderProtector->restoreAndValidate(
            $text,
            trim($content),
            $placeholders,
        );
    }
}
