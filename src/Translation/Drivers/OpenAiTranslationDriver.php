<?php

namespace KeypointSolutions\LaravelVox\Translation\Drivers;

use KeypointSolutions\LaravelVox\Ai\LaravelPlaceholderProtector;
use KeypointSolutions\LaravelVox\Ai\OpenAiClient;
use KeypointSolutions\LaravelVox\Ai\OpenAiModelCatalog;
use KeypointSolutions\LaravelVox\Ai\TranslationPromptBuilder;
use KeypointSolutions\LaravelVox\Support\VoxConfig;
use KeypointSolutions\LaravelVox\Support\VoxSettingsRepository;

class OpenAiTranslationDriver extends AiTranslationDriver
{
    public function __construct(
        TranslationPromptBuilder $promptBuilder,
        LaravelPlaceholderProtector $placeholderProtector,
        private OpenAiClient $client,
        private OpenAiModelCatalog $catalog,
        private VoxSettingsRepository $settings,
    ) {
        parent::__construct($promptBuilder, $placeholderProtector);
    }

    protected function complete(string $instructions, string $input): ?string
    {
        $model = $this->catalog->resolve((string) $this->settings->get('translate_model', VoxConfig::translateModel()));

        return $this->extractOutputText($this->client->createResponse($model, $instructions, $input));
    }

    protected function providerName(): string
    {
        return 'OpenAI';
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
