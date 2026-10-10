<?php

namespace KeypointSolutions\LaravelVox\Ai;

class OpenAiModelCatalog
{
    public const DEFAULT_MODEL = 'gpt-6-luna';

    /**
     * @var array<string, string>
     */
    private const LABELS = [
        'gpt-6-luna' => 'GPT-6 Luna · Recommended',
        'gpt-6.1-sol' => 'GPT-6.1 Sol · More capable',
        'gpt-6-astra' => 'GPT-6 Astra · Highest quality',
        'gpt-6-sol' => 'GPT-6 Sol',
        'gpt-5.6-luna' => 'GPT-5.6 Luna',
        'gpt-5.6-terra' => 'GPT-5.6 Terra',
        'gpt-5.6-sol' => 'GPT-5.6 Sol',
        'gpt-5.4-mini' => 'GPT-5.4 mini',
        'gpt-5.4-nano' => 'GPT-5.4 nano',
    ];

    /**
     * The lowest reasoning effort each model family accepts, keyed by model name prefix.
     * Translation is not a reasoning task, so anything above the minimum only adds cost and latency.
     *
     * @var array<string, string>
     */
    private const LOWEST_REASONING_EFFORT = [
        'gpt-5.6' => 'none',
        'gpt-6-luna' => 'none',
        'gpt-6-sol' => 'none',
        'gpt-6.1-sol' => 'low',
        'gpt-6-astra' => 'low',
    ];

    /**
     * @var array<int, string>
     */
    private const EXCLUDED_FRAGMENTS = [
        'audio',
        'codex',
        'image',
        'instruct',
        'moderation',
        'realtime',
        'search',
        'transcribe',
        'tts',
    ];

    /**
     * Text models usable for translation: GPT-4.1, GPT-4o, and every GPT generation from 5 onwards
     * with at most one tier suffix (such as "-mini" or "-luna"). Dated snapshots and aliases are left out.
     */
    public function supports(string $model): bool
    {
        if (preg_match('/^gpt-(?:4\.1(?:-(?:mini|nano))?|4o(?:-mini)?|(\d+)(?:\.\d+)?(?:-[a-z]+)?)$/', $model, $matches) !== 1) {
            return false;
        }

        if (isset($matches[1]) && (int) $matches[1] < 5) {
            return false;
        }

        foreach (self::EXCLUDED_FRAGMENTS as $fragment) {
            if (str_contains($model, $fragment)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The model to use: the configured one, unless it belongs to another provider,
     * which can happen right after the driver is switched.
     */
    public function resolve(string $configuredModel): string
    {
        return str_starts_with($configuredModel, 'claude-') ? self::DEFAULT_MODEL : $configuredModel;
    }

    public function label(string $model): string
    {
        if (isset(self::LABELS[$model])) {
            return self::LABELS[$model];
        }

        $parts = explode('-', $model);

        if ($parts[0] !== 'gpt' || count($parts) < 2) {
            return $model;
        }

        return 'GPT-'.implode(' ', array_slice($parts, 1));
    }

    /**
     * The reasoning effort to request for translation, or null to leave the provider default
     * for models whose accepted values are not known.
     */
    public function translationReasoningEffort(string $model): ?string
    {
        foreach (self::LOWEST_REASONING_EFFORT as $prefix => $effort) {
            if (str_starts_with($model, $prefix)) {
                return $effort;
            }
        }

        return null;
    }

    /**
     * @param  array<int, string>  $models
     * @return array<int, array{value: string, label: string, available: bool}>
     */
    public function options(array $models, string $configuredModel): array
    {
        $returned = in_array($configuredModel, $models, true);
        $models = array_values(array_unique(array_filter(
            $models,
            fn (mixed $model): bool => is_string($model) && $this->supports($model)
        )));

        usort($models, function (string $left, string $right): int {
            $leftRank = array_search($left, array_keys(self::LABELS), true);
            $rightRank = array_search($right, array_keys(self::LABELS), true);
            $leftRank = $leftRank === false ? PHP_INT_MAX : $leftRank;
            $rightRank = $rightRank === false ? PHP_INT_MAX : $rightRank;

            return $leftRank <=> $rightRank ?: strnatcasecmp($right, $left);
        });

        $options = array_map(fn (string $model): array => [
            'value' => $model,
            'label' => $this->label($model),
            'available' => true,
        ], $models);

        if ($configuredModel !== '' && ! in_array($configuredModel, $models, true)) {
            array_unshift($options, [
                'value' => $configuredModel,
                'label' => $this->label($configuredModel).($returned ? ' · Configured' : ' · Configured, not offered by this account'),
                'available' => $returned,
            ]);
        }

        return $options;
    }
}
