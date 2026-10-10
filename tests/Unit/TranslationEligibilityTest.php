<?php

use KeypointSolutions\LaravelVox\Translation\TranslationEligibility;

it('treats absent and flagged wording as missing but keeps blank wording as deliberate', function (mixed $value, bool $missing): void {
    config()->set('vox.parse.missing_translation_prefix', 'TODO:');

    expect(app(TranslationEligibility::class)->isMissing($value))->toBe($missing);
})->with([
    'absent' => [null, true],
    'flagged' => ['TODO:Hello', true],
    'empty string' => ['', false],
    'whitespace' => ['  ', false],
    'wording' => ['Hello', false],
]);

it('flags nothing when the missing marker is disabled', function (): void {
    config()->set('vox.parse.missing_translation_prefix', '');
    $eligibility = app(TranslationEligibility::class);

    expect($eligibility->isFlagged('Hello'))->toBeFalse()
        ->and($eligibility->isMissing('Hello'))->toBeFalse()
        ->and($eligibility->isMissing(null))->toBeTrue();
});

it('never uses blank, flagged or placeholder wording as a translation source', function (string $key, mixed $value, bool $usable): void {
    config()->set('vox.parse.missing_translation_prefix', 'TODO:');

    expect(app(TranslationEligibility::class)->canTranslateSource($key, $value))->toBe($usable);
})->with([
    'wording' => ['greeting', 'Hello', true],
    'empty string' => ['greeting', '', false],
    'whitespace' => ['greeting', '   ', false],
    'absent' => ['greeting', null, false],
    'flagged' => ['greeting', 'TODO:Hello', false],
    'placeholder key repeated as wording' => ['snake_key', 'snake_key', false],
]);
