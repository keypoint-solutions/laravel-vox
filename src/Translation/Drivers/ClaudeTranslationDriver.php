<?php

namespace KeypointSolutions\LaravelVox\Translation\Drivers;

use KeypointSolutions\LaravelVox\Ai\ClaudeAiClient;
use KeypointSolutions\LaravelVox\Ai\ClaudeModelCatalog;
use KeypointSolutions\LaravelVox\Ai\LaravelPlaceholderProtector;
use KeypointSolutions\LaravelVox\Ai\TranslationPromptBuilder;
use KeypointSolutions\LaravelVox\Support\VoxConfig;
use KeypointSolutions\LaravelVox\Support\VoxSettingsRepository;

class ClaudeTranslationDriver extends AiTranslationDriver
{
    public function __construct(
        TranslationPromptBuilder $promptBuilder,
        LaravelPlaceholderProtector $placeholderProtector,
        private ClaudeAiClient $client,
        private ClaudeModelCatalog $catalog,
        private VoxSettingsRepository $settings,
    ) {
        parent::__construct($promptBuilder, $placeholderProtector);
    }

    protected function complete(string $instructions, string $input): ?string
    {
        $model = $this->catalog->resolve((string) $this->settings->get('translate_model', VoxConfig::translateModel()));

        return $this->client->createMessage($model, $instructions, $input);
    }

    protected function providerName(): string
    {
        return 'Claude';
    }
}
