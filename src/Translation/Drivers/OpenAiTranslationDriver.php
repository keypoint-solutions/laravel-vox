<?php

namespace KeypointSolutions\LaravelVox\Translation\Drivers;

use KeypointSolutions\LaravelVox\Support\OpenAiClient;
use KeypointSolutions\LaravelVox\Support\VoxSettingsRepository;
use KeypointSolutions\LaravelVox\Translation\LaravelPlaceholderProtector;
use KeypointSolutions\LaravelVox\Translation\TranslationPromptBuilder;
use RuntimeException;

class OpenAiTranslationDriver implements TranslationDriver
{
    public function __construct(
        private TranslationPromptBuilder $promptBuilder,
        private LaravelPlaceholderProtector $placeholderProtector,
        private OpenAiClient $client,
        private VoxSettingsRepository $settings,
    ) {}

    /**
     * @param  array<string, mixed>  $context
     */
    public function translate(string $text, string $sourceLocale, string $targetLocale, array $context = []): string
    {
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

        $payload = $this->client->createResponse(
            (string) $this->settings->get(
                'translate_model',
                config('vox.translate.model', 'gpt-5.6-luna')
            ),
            $systemPrompt,
            $protectedText,
        );
        $content = $this->extractOutputText($payload);

        if (! is_string($content) || trim($content) === '') {
            throw new RuntimeException('OpenAI response did not contain a translation.');
        }

        return $this->placeholderProtector->restoreAndValidate(
            $text,
            trim($content),
            $placeholders,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function extractOutputText(array $payload): ?string
    {
        $output = $payload['output'] ?? null;

        if (! is_array($output)) {
            return null;
        }

        foreach ($output as $item) {
            if (! is_array($item) || ($item['type'] ?? null) !== 'message') {
                continue;
            }

            $content = $item['content'] ?? null;

            if (! is_array($content)) {
                continue;
            }

            foreach ($content as $part) {
                if (
                    is_array($part)
                    && ($part['type'] ?? null) === 'output_text'
                    && is_string($part['text'] ?? null)
                ) {
                    return $part['text'];
                }
            }
        }

        return null;
    }
}
