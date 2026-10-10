<?php

namespace KeypointSolutions\LaravelVox\Ai;

class ClaudeModelCatalog
{
    public const DEFAULT_MODEL = 'claude-haiku-5-5';

    /**
     * @var array<string, string>
     */
    private const LABELS = [
        'claude-haiku-5-5' => 'Claude Haiku 5.5 · Recommended',
        'claude-sonnet-5-5' => 'Claude Sonnet 5.5 · More capable',
        'claude-opus-5-5' => 'Claude Opus 5.5 · Highest quality',
    ];

    /**
     * Model families that accept an effort level. Translation is not a reasoning task,
     * so they are asked for the lowest one; other models are left at the provider default.
     *
     * @var array<int, string>
     */
    private const SUPPORTS_EFFORT = [
        'claude-haiku-5',
        'claude-sonnet-5',
        'claude-opus-5',
        'claude-fable-5',
        'claude-opus-4-8',
        'claude-opus-4-7',
        'claude-opus-4-6',
        'claude-sonnet-4-6',
    ];

    public function supports(string $model): bool
    {
        return str_starts_with($model, 'claude-');
    }

    /**
     * The model to use: the configured one when it is a Claude model, otherwise the default.
     * The configured model can belong to another provider right after the driver is switched.
     */
    public function resolve(string $configuredModel): string
    {
        return $this->supports($configuredModel) ? $configuredModel : self::DEFAULT_MODEL;
    }

    public function translationEffort(string $model): ?string
    {
        foreach (self::SUPPORTS_EFFORT as $prefix) {
            if (str_starts_with($model, $prefix)) {
                return 'low';
            }
        }

        return null;
    }

    /**
     * @param  array<string, string>  $models  Display names keyed by model ID, as returned by the provider.
     * @return array<int, array{value: string, label: string, available: bool}>
     */
    public function options(array $models, string $configuredModel): array
    {
        $models = array_filter($models, fn (string $id): bool => $this->supports($id), ARRAY_FILTER_USE_KEY);
        $ids = array_keys($models);
        $ranks = array_flip(array_keys(self::LABELS));

        usort($ids, fn (string $left, string $right): int => ($ranks[$left] ?? PHP_INT_MAX) <=> ($ranks[$right] ?? PHP_INT_MAX)
            ?: strnatcasecmp($right, $left));

        $options = array_map(fn (string $id): array => [
            'value' => $id,
            'label' => self::LABELS[$id] ?? $models[$id],
            'available' => true,
        ], $ids);

        if ($configuredModel !== '' && ! in_array($configuredModel, $ids, true)) {
            array_unshift($options, [
                'value' => $configuredModel,
                'label' => (self::LABELS[$configuredModel] ?? $configuredModel).' · Configured, not offered by this account',
                'available' => false,
            ]);
        }

        return $options;
    }
}
