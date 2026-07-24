<?php

use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use KeypointSolutions\LaravelVox\Translation\Drivers\OpenAiTranslationDriver;

beforeEach(function (): void {
    config()->set('vox.translate.providers.openai.api_key', 'test-key');
    config()->set('vox.translate.model', 'gpt-5.4-mini');
    config()->set('vox.translate.prompt', 'Translate :text from :source to :target.');
    config()->set('vox.translate.guidance', '');
    config()->set('vox.translate.terms', ['do_not_translate' => [], 'fixed' => []]);
    config()->set('vox.translate.use_context', false);
});

it('protects and restores Laravel placeholders around the model request', function () {
    Http::fake([
        '*' => Http::response([
            'output' => [
                [
                    'type' => 'message',
                    'content' => [
                        [
                            'type' => 'output_text',
                            'text' => 'Bonjour __LARAVEL_PLACEHOLDER_0__, vous avez __LARAVEL_PLACEHOLDER_1__ messages.',
                        ],
                    ],
                ],
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
        $serializedRequest = json_encode($request->data(), JSON_THROW_ON_ERROR);

        return $request->url() === 'https://api.openai.com/v1/responses'
            && $request['model'] === 'gpt-5.4-mini'
            && ! array_key_exists('reasoning', $request->data())
            && $request['store'] === false
            && $request['input'] === 'Hello __LARAVEL_PLACEHOLDER_0__, you have __LARAVEL_PLACEHOLDER_1__ messages.'
            && str_contains($request['instructions'], 'Preserve every token exactly.')
            && ! str_contains($serializedRequest, ':name')
            && ! str_contains($serializedRequest, ':count');
    });
});

it('keeps GPT-5.6 translation requests at the non-reasoning cost profile', function () {
    config()->set('vox.translate.model', 'gpt-5.6-luna');

    Http::fake([
        '*' => Http::response([
            'output' => [[
                'type' => 'message',
                'content' => [[
                    'type' => 'output_text',
                    'text' => 'Bonjour',
                ]],
            ]],
        ]),
    ]);

    expect(app(OpenAiTranslationDriver::class)->translate('Hello', 'en', 'fr'))
        ->toBe('Bonjour');

    Http::assertSent(fn (Request $request): bool => $request['reasoning'] === ['effort' => 'none']);
});

it('preserves repeated and reordered placeholders', function () {
    Http::fake([
        '*' => Http::response([
            'output' => [
                [
                    'type' => 'message',
                    'content' => [[
                        'type' => 'output_text',
                        'text' => '__LARAVEL_PLACEHOLDER_2__: __LARAVEL_PLACEHOLDER_0__ et encore __LARAVEL_PLACEHOLDER_1__.',
                    ]],
                ],
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
            'output' => [
                [
                    'type' => 'message',
                    'content' => [[
                        'type' => 'output_text',
                        'text' => 'Téléchargez PDF:er à https://example.com/files:latest pour __LARAVEL_PLACEHOLDER_0__.',
                    ]],
                ],
            ],
        ]),
    ]);

    $translation = app(OpenAiTranslationDriver::class)->translate(
        'Download PDF:er at https://example.com/files:latest for :name.',
        'en',
        'fr',
    );

    expect($translation)->toBe('Téléchargez PDF:er à https://example.com/files:latest pour :name.');

    Http::assertSent(fn (Request $request): bool => $request['input']
        === 'Download PDF:er at https://example.com/files:latest for __LARAVEL_PLACEHOLDER_0__.');
});

it('allows translated prefixes to be attached to restored placeholders', function () {
    Http::fake([
        '*' => Http::response([
            'output' => [
                [
                    'type' => 'message',
                    'content' => [[
                        'type' => 'output_text',
                        'text' => 'بين __LARAVEL_PLACEHOLDER_0__ و__LARAVEL_PLACEHOLDER_1__ عنصرًا.',
                    ]],
                ],
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
            'output' => [
                [
                    'type' => 'message',
                    'content' => [[
                        'type' => 'output_text',
                        'text' => 'Bonjour __LARAVEL_PLACEHOLDER_NAME__.',
                    ]],
                ],
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

it('rejects a response without an output text item', function () {
    Http::fake([
        '*' => Http::response([
            'output' => [
                ['type' => 'reasoning', 'summary' => []],
            ],
        ]),
    ]);

    expect(fn () => app(OpenAiTranslationDriver::class)->translate('Hello', 'en', 'fr'))
        ->toThrow(RuntimeException::class, 'OpenAI response did not contain a translation.');
});
