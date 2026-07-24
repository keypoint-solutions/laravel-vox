<?php

use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use KeypointSolutions\LaravelVox\Translation\Drivers\OpenAiTranslationDriver;

beforeEach(function (): void {
    config()->set('vox.translate.openai.api_key', 'test-key');
    config()->set('vox.translate.openai.model', 'gpt-5.4-mini');
    config()->set('vox.translate.openai.endpoint', 'https://api.openai.test/v1/chat/completions');
    config()->set('vox.translate.openai.temperature', 0.2);
    config()->set('vox.translate.prompt', 'Translate :text from :source to :target.');
    config()->set('vox.translate.terms', ['do_not_translate' => [], 'fixed' => []]);
    config()->set('vox.translate.use_context', false);
});

it('protects and restores Laravel placeholders around the model request', function () {
    Http::fake([
        '*' => Http::response([
            'choices' => [
                ['message' => ['content' => 'Bonjour __LARAVEL_PLACEHOLDER_0__, vous avez __LARAVEL_PLACEHOLDER_1__ messages.']],
            ],
        ]),
    ]);

    $translation = app(OpenAiTranslationDriver::class)->translate(
        'Hello :name, you have :count messages.',
        'en',
        'fr',
    );

    expect($translation)->toBe('Bonjour :name, vous avez :count messages.');

    Http::assertSent(function (Request $request): bool {
        $messages = $request['messages'];
        $serializedMessages = json_encode($messages, JSON_THROW_ON_ERROR);

        return $request['model'] === 'gpt-5.4-mini'
            && $request['temperature'] === 0.2
            && $messages[1]['content'] === 'Hello __LARAVEL_PLACEHOLDER_0__, you have __LARAVEL_PLACEHOLDER_1__ messages.'
            && str_contains($messages[0]['content'], 'Preserve every token exactly.')
            && ! str_contains($serializedMessages, ':name')
            && ! str_contains($serializedMessages, ':count');
    });
});

it('preserves repeated and reordered placeholders', function () {
    Http::fake([
        '*' => Http::response([
            'choices' => [
                ['message' => ['content' => '__LARAVEL_PLACEHOLDER_2__: __LARAVEL_PLACEHOLDER_0__ et encore __LARAVEL_PLACEHOLDER_1__.']],
            ],
        ]),
    ]);

    $translation = app(OpenAiTranslationDriver::class)->translate(
        ':name and :name have :count messages.',
        'en',
        'fr',
    );

    expect($translation)->toBe(':count: :name et encore :name.');
});

it('does not treat colon suffixes as Laravel placeholders', function () {
    Http::fake([
        '*' => Http::response([
            'choices' => [
                ['message' => ['content' => 'Téléchargez PDF:er à https://example.com/files:latest pour __LARAVEL_PLACEHOLDER_0__.']],
            ],
        ]),
    ]);

    $translation = app(OpenAiTranslationDriver::class)->translate(
        'Download PDF:er at https://example.com/files:latest for :name.',
        'en',
        'fr',
    );

    expect($translation)->toBe('Téléchargez PDF:er à https://example.com/files:latest pour :name.');

    Http::assertSent(fn (Request $request): bool => $request['messages'][1]['content']
        === 'Download PDF:er at https://example.com/files:latest for __LARAVEL_PLACEHOLDER_0__.');
});

it('allows translated prefixes to be attached to restored placeholders', function () {
    Http::fake([
        '*' => Http::response([
            'choices' => [
                ['message' => ['content' => 'بين __LARAVEL_PLACEHOLDER_0__ و__LARAVEL_PLACEHOLDER_1__ عنصرًا.']],
            ],
        ]),
    ]);

    $translation = app(OpenAiTranslationDriver::class)->translate(
        'Between :min and :max items.',
        'en',
        'ar',
    );

    expect($translation)->toBe('بين :min و:max عنصرًا.');
});

it('rejects a translation when the model changes a protected token', function () {
    Http::fake([
        '*' => Http::response([
            'choices' => [
                ['message' => ['content' => 'Bonjour __LARAVEL_PLACEHOLDER_NAME__.']],
            ],
        ]),
    ]);

    expect(fn () => app(OpenAiTranslationDriver::class)->translate('Hello :name.', 'en', 'fr'))
        ->toThrow(RuntimeException::class, 'Laravel placeholder mismatch. Expected [:name], found [].');
});

it('throws for unsuccessful OpenAI responses', function () {
    Http::fake([
        '*' => Http::response([
            'error' => ['message' => 'Unsupported parameter.'],
        ], 400),
    ]);

    expect(fn () => app(OpenAiTranslationDriver::class)->translate('Hello', 'en', 'fr'))
        ->toThrow(RequestException::class);
});
