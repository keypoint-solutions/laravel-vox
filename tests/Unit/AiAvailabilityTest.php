<?php

use KeypointSolutions\LaravelVox\Ai\AiAvailability;
use KeypointSolutions\LaravelVox\Tests\Support\EchoTranslationDriver;

it('reports AI as unavailable until a provider is fully configured', function (string $driver, ?string $apiKey, bool $available): void {
    config()->set('vox.translate.driver', $driver);
    config()->set('vox.translate.providers.openai.api_key', $apiKey);
    $ai = app(AiAvailability::class);

    expect($ai->available())->toBe($available)
        ->and($ai->reason() === null)->toBe($available);

    if (! $available) {
        expect($ai->reason())->toStartWith('AI translation is not configured.')
            ->and($ai->canChoose())->toBeFalse();
    }
})->with([
    'no driver' => ['null', null, false],
    'OpenAI without a key' => ['openai', null, false],
    'OpenAI with an empty key' => ['openai', '', false],
    'OpenAI with a key' => ['openai', 'test-key', true],
    'custom driver class' => [EchoTranslationDriver::class, null, true],
    'unknown driver' => ['deepl', null, false],
]);

it('offers AI wording choices only for drivers that support them', function (): void {
    config()->set('vox.translate.driver', 'openai');
    config()->set('vox.translate.providers.openai.api_key', 'test-key');
    expect(app(AiAvailability::class)->canChoose())->toBeTrue();

    config()->set('vox.translate.driver', EchoTranslationDriver::class);
    expect(app(AiAvailability::class)->canChoose())->toBeFalse();
});
