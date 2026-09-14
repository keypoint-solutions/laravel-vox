<?php

namespace KeypointSolutions\LaravelVox\Support;

class OpenAiModelCatalog
{
    /**
     * @var array<string, string>
     */
    private const LABELS = [
        'gpt-5.6-luna' => 'GPT-5.6 Luna · Recommended',
        'gpt-5.4-mini' => 'GPT-5.4 mini',
        'gpt-5.4-nano' => 'GPT-5.4 nano · Lowest cost',
        'gpt-5.6-terra' => 'GPT-5.6 Terra · More capable',
        'gpt-5.6-sol' => 'GPT-5.6 Sol · Highest quality',
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

    public function supports(string $model): bool
    {
        if (preg_match('/^gpt-(?:5(?:\.\d+)?(?:-(?:sol|terra|luna|mini|nano))?|4\.1(?:-(?:mini|nano))?|4o(?:-mini)?)$/', $model) !== 1) {
            return false;
        }

        foreach (self::EXCLUDED_FRAGMENTS as $fragment) {
            if (str_contains($model, $fragment)) {
                return false;
            }
        }

        return true;
    }

    public function label(string $model): string
    {
        if (isset(self::LABELS[$model])) {
            return self::LABELS[$model];
        }

        $parts = explode('-', $model);
        $parts[0] = strtoupper($parts[0]);

        return implode(' ', $parts);
    }

    /**
     * @param  array<int, string>  $models
     * @return array<int, array{value: string, label: string, available: bool}>
     */
    public function options(array $models, string $configuredModel): array
    {
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
                'label' => $this->label($configuredModel).' · Configured, not returned',
                'available' => false,
            ]);
        }

        return $options;
    }
}
