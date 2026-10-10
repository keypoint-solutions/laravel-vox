<?php

use KeypointSolutions\LaravelVox\Ai\OpenAiModelCatalog;

it('accepts current and future GPT text models and rejects other kinds', function (string $model, bool $supported): void {
    expect((new OpenAiModelCatalog)->supports($model))->toBe($supported);
})->with([
    ['gpt-5', true],
    ['gpt-5.6-luna', true],
    ['gpt-5.4-mini', true],
    ['gpt-6-luna', true],
    ['gpt-6.1-sol', true],
    ['gpt-6-astra', true],
    ['gpt-4.1', true],
    ['gpt-4.1-nano', true],
    ['gpt-4o-mini', true],
    ['gpt-4', false],
    ['gpt-4-turbo', false],
    ['gpt-3.5-turbo', false],
    ['gpt-5-2025-08-07', false],
    ['gpt-5-chat-latest', false],
    ['gpt-5-codex', false],
    ['gpt-6-audio', false],
    ['gpt-image-1', false],
    ['text-embedding-3-small', false],
    ['o3-mini', false],
]);

it('lists newer generations and keeps the configured model selectable', function (): void {
    $options = (new OpenAiModelCatalog)->options(['gpt-6-luna', 'gpt-5.6-luna', 'gpt-image-1', 'gpt-5-chat-latest'], 'gpt-5-chat-latest');

    expect(array_column($options, 'value'))->toBe(['gpt-5-chat-latest', 'gpt-6-luna', 'gpt-5.6-luna'])
        ->and($options[0])->toBe(['value' => 'gpt-5-chat-latest', 'label' => 'GPT-5 chat latest · Configured', 'available' => true]);
});

it('marks a configured model the account does not offer', function (): void {
    $options = (new OpenAiModelCatalog)->options(['gpt-5.6-luna'], 'gpt-9-nova');

    expect($options[0])->toBe([
        'value' => 'gpt-9-nova',
        'label' => 'GPT-9 nova · Configured, not offered by this account',
        'available' => false,
    ]);
});

it('labels the current generation and leaves older models untagged', function (): void {
    $catalog = new OpenAiModelCatalog;

    expect($catalog->label('gpt-6-luna'))->toBe('GPT-6 Luna · Recommended')
        ->and($catalog->label('gpt-6.1-sol'))->toBe('GPT-6.1 Sol · More capable')
        ->and($catalog->label('gpt-6-astra'))->toBe('GPT-6 Astra · Highest quality')
        ->and($catalog->label('gpt-5.6-luna'))->toBe('GPT-5.6 Luna')
        ->and($catalog->label('gpt-5.4-nano'))->toBe('GPT-5.4 nano')
        ->and($catalog->label('gpt-5.5-pro'))->toBe('GPT-5.5 pro');
});

it('requests the lowest reasoning effort each model accepts', function (string $model, ?string $effort): void {
    expect((new OpenAiModelCatalog)->translationReasoningEffort($model))->toBe($effort);
})->with([
    ['gpt-6-luna', 'none'],
    ['gpt-6-sol', 'none'],
    ['gpt-6.1-sol', 'low'],
    ['gpt-6-astra', 'low'],
    ['gpt-5.6-terra', 'none'],
    ['gpt-5.4-mini', null],
    ['gpt-7-nova', null],
]);
