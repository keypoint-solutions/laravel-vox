<?php

namespace KeypointSolutions\LaravelVox\Ai;

use KeypointSolutions\LaravelVox\Support\VoxConfig;
use KeypointSolutions\LaravelVox\Translation\Drivers\TranslationChoiceDriver;
use KeypointSolutions\LaravelVox\Translation\Drivers\TranslationDriver;
use KeypointSolutions\LaravelVox\Translation\Drivers\TranslationDriverFactory;

/**
 * Answers whether AI translation can be used with the current configuration.
 * An AI provider is optional: every AI feature checks here before it runs.
 */
class AiAvailability
{
    /**
     * Built-in providers: display name and the config key holding the API key.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const PROVIDERS = [
        'openai' => ['OpenAI', 'vox.translate.providers.openai.api_key'],
        'claude' => ['Anthropic', 'vox.translate.providers.anthropic.api_key'],
    ];

    public function __construct(private TranslationDriverFactory $drivers) {}

    public function available(): bool
    {
        return $this->reason() === null;
    }

    /**
     * Why AI translation cannot be used, or null when it can.
     */
    public function reason(): ?string
    {
        $driver = VoxConfig::translateDriver();

        if ($driver === 'null') {
            return 'AI translation is not configured. Set a translation driver in vox.translate.driver.';
        }

        if (isset(self::PROVIDERS[$driver])) {
            [$name, $keyPath] = self::PROVIDERS[$driver];
            $apiKey = config($keyPath);

            return is_string($apiKey) && $apiKey !== ''
                ? null
                : "AI translation is not configured. Add the {$name} API key to use it.";
        }

        return is_a($driver, TranslationDriver::class, true)
            ? null
            : "AI translation is not configured. Unsupported translation driver [{$driver}].";
    }

    /**
     * Whether the driver can also pick between two candidate wordings.
     */
    public function canChoose(): bool
    {
        return $this->available() && $this->drivers->make() instanceof TranslationChoiceDriver;
    }
}
