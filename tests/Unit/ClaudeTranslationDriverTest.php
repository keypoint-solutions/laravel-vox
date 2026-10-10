<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use KeypointSolutions\LaravelVox\Ai\AiAvailability;
use KeypointSolutions\LaravelVox\Ai\AiModelDiscovery;
use KeypointSolutions\LaravelVox\Ai\AiProviderException;
use KeypointSolutions\LaravelVox\Ai\ClaudeAiClient;
use KeypointSolutions\LaravelVox\Ai\ClaudeModelCatalog;
use KeypointSolutions\LaravelVox\Translation\Drivers\ClaudeTranslationDriver;
use KeypointSolutions\LaravelVox\Translation\Drivers\TranslationDriverFactory;

beforeEach(function (): void {
    config()->set('vox.translate.driver', 'claude');
    config()->set('vox.translate.providers.anthropic.api_key', 'test-key');
    config()->set('vox.translate.model', 'claude-haiku-5-5');
    config()->set('vox.translate.prompt', 'Translate :text from :source to :target.');
    config()->set('vox.translate.guidance', '');
    config()->set('vox.translate.terms', ['do_not_translate' => [], 'fixed' => []]);
    config()->set('vox.translate.use_context', false);
});

function claudeReply(string $text, string $stopReason = 'end_turn'): array
{
    return [
        'type' => 'message',
        'role' => 'assistant',
        'content' => [
            ['type' => 'thinking', 'thinking' => '', 'signature' => 'abc'],
            ['type' => 'text', 'text' => $text],
        ],
        'stop_reason' => $stopReason,
    ];
}

it('is the driver the factory returns for the claude setting and is reported as available', function (): void {
    expect(app(TranslationDriverFactory::class)->make())->toBeInstanceOf(ClaudeTranslationDriver::class)
        ->and(app(AiAvailability::class)->available())->toBeTrue()
        ->and(app(AiAvailability::class)->canChoose())->toBeTrue();

    config()->set('vox.translate.providers.anthropic.api_key', null);

    expect(app(AiAvailability::class)->reason())->toBe('AI translation is not configured. Add the Anthropic API key to use it.');
});

it('sends a Messages API request and reads the text after any thinking block', function (): void {
    Http::fake(['*' => Http::response(claudeReply('Bonjour __LARAVEL_PLACEHOLDER_0__.'))]);

    expect(app(ClaudeTranslationDriver::class)->translate('Hello :name.', 'en', 'fr'))->toBe('Bonjour :name.');

    Http::assertSent(fn (Request $request): bool => $request->url() === ClaudeAiClient::MESSAGES_ENDPOINT
        && $request->hasHeader('x-api-key', 'test-key')
        && $request->hasHeader('anthropic-version', '2023-06-01')
        && $request['model'] === 'claude-haiku-5-5'
        && $request['max_tokens'] === 16000
        && $request['output_config'] === ['effort' => 'low']
        && ! isset($request['thinking'], $request['temperature'])
        && str_contains($request['system'], 'Preserve every token exactly.')
        && $request['messages'] === [['role' => 'user', 'content' => 'Hello __LARAVEL_PLACEHOLDER_0__.']]);
});

it('falls back to the default Claude model when the saved model belongs to another provider', function (): void {
    config()->set('vox.translate.model', 'gpt-6-luna');
    Http::fake(['*' => Http::response(claudeReply('Bonjour'))]);

    app(ClaudeTranslationDriver::class)->translate('Hello', 'en', 'fr');

    Http::assertSent(fn (Request $request): bool => $request['model'] === ClaudeModelCatalog::DEFAULT_MODEL);
});

it('asks for low effort only from models that accept an effort level', function (string $model, ?string $effort): void {
    expect((new ClaudeModelCatalog)->translationEffort($model))->toBe($effort);
})->with([
    ['claude-haiku-5-5', 'low'],
    ['claude-sonnet-5-5', 'low'],
    ['claude-opus-5-5', 'low'],
    ['claude-fable-5-1', 'low'],
    ['claude-opus-4-8', 'low'],
    ['claude-haiku-4-5', null],
    ['claude-sonnet-4-5', null],
]);

it('reports provider failures with the provider message', function (): void {
    Http::fake(['*' => Http::response([
        'type' => 'error',
        'error' => ['type' => 'invalid_request_error', 'message' => 'Your credit balance is too low to access the Anthropic API.'],
    ], 400)]);

    expect(fn () => app(ClaudeTranslationDriver::class)->translate('Hello', 'en', 'fr'))
        ->toThrow(AiProviderException::class, 'Claude request failed (HTTP 400): Your credit balance is too low to access the Anthropic API.');
});

it('rejects refused, truncated and empty replies', function (array $reply, string $message): void {
    Http::fake(['*' => Http::response($reply)]);

    expect(fn () => app(ClaudeTranslationDriver::class)->translate('Hello', 'en', 'fr'))
        ->toThrow(RuntimeException::class, $message);
})->with([
    'refusal' => [claudeReply('', 'refusal'), 'Claude declined to process this text.'],
    'max tokens' => [claudeReply('Bonj', 'max_tokens'), 'Claude stopped before finishing'],
    'no text' => [['content' => [], 'stop_reason' => 'end_turn'], 'Claude response did not contain a translation.'],
]);

it('chooses between two wordings and accepts JSON wrapped in a code fence', function (): void {
    Http::fake(['*' => Http::response(claudeReply("```json\n{\"choice\": \"incoming\", \"reason\": \"Incoming is translated.\"}\n```"))]);

    $choice = app(ClaudeTranslationDriver::class)->choose([
        'local' => '🚩Hello', 'incoming' => 'Bonjour', 'locale' => 'fr', 'default_locale' => 'en',
        'default_value' => 'Hello', 'key' => 'messages.hello', 'missing_prefix' => '🚩',
    ]);

    expect($choice)->toBe(['choice' => 'incoming', 'reason' => 'Incoming is translated.']);
});

it('lists the Claude models the account offers, following pagination', function (): void {
    Http::fakeSequence()
        ->push(['data' => [
            ['id' => 'claude-opus-5-5', 'display_name' => 'Claude Opus 5.5'],
            ['id' => 'claude-haiku-5-5', 'display_name' => 'Claude Haiku 5.5'],
        ], 'has_more' => true, 'last_id' => 'claude-haiku-5-5'])
        ->push(['data' => [
            ['id' => 'claude-sonnet-5', 'display_name' => 'Claude Sonnet 5'],
        ], 'has_more' => false, 'last_id' => 'claude-sonnet-5']);

    $result = app(AiModelDiscovery::class)->discover(refresh: true);

    expect($result['provider'])->toBe(['id' => 'claude', 'label' => 'Claude', 'credentials_set' => true])
        ->and($result['status'])->toBe('connected')
        ->and($result['model'])->toBe('claude-haiku-5-5')
        ->and($result['models'])->toBe([
            ['value' => 'claude-haiku-5-5', 'label' => 'Claude Haiku 5.5 · Recommended', 'available' => true],
            ['value' => 'claude-opus-5-5', 'label' => 'Claude Opus 5.5 · Highest quality', 'available' => true],
            ['value' => 'claude-sonnet-5', 'label' => 'Claude Sonnet 5', 'available' => true],
        ]);

    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), ClaudeAiClient::MODELS_ENDPOINT)
        && $request->hasHeader('x-api-key', 'test-key'));
});
