<?php

use Illuminate\Validation\ValidationException;
use KeypointSolutions\LaravelVox\Models\VoxTranslation;
use KeypointSolutions\LaravelVox\Translation\RemoteTranslationSnapshot;

it('validates a large remote snapshot without expanding every wildcard rule in memory', function (): void {
    $values = [];

    for ($index = 0; $index < 10000; $index++) {
        $values[] = ['group' => 'messages', 'key' => 'key_'.$index, 'locale' => 'en', 'value' => 'Published wording'];
    }

    memory_reset_peak_usage();
    $baseline = memory_get_usage(true);
    $validated = app(RemoteTranslationSnapshot::class)->validate($values);
    $extraMemory = memory_get_peak_usage(true) - $baseline;

    expect($validated)->toBe($values)
        ->and($extraMemory)->toBeLessThan(32 * 1024 * 1024);
});

it('preserves published and draft wording across export batches', function (): void {
    for ($index = 0; $index < 205; $index++) {
        $translation = VoxTranslation::factory()->create([
            'group' => 'messages', 'key' => 'key_'.$index,
        ]);
        $translation->values()->create([
            'locale' => 'en', 'value' => 'Draft '.$index, 'file_value' => 'Published '.$index,
        ]);
    }

    $snapshot = app(RemoteTranslationSnapshot::class);
    $published = $snapshot->export()['values'];
    $drafts = $snapshot->export(true)['values'];

    expect($published)->toHaveCount(205)
        ->and($drafts)->toHaveCount(205);

    foreach ($published as $index => $entry) {
        expect($entry['key'])->toBe('key_'.$index)
            ->and($entry['value'])->toBe('Published '.$index)
            ->and($drafts[$index]['value'])->toBe('Draft '.$index);
    }
});

it('rejects invalid entries during incremental validation', function (array $entry): void {
    app(RemoteTranslationSnapshot::class)->validate([$entry]);
})->with([
    'extra field' => [['group' => 'messages', 'key' => 'hello', 'locale' => 'en', 'value' => '', 'extra' => true]],
    'invalid locale' => [['group' => 'messages', 'key' => 'hello', 'locale' => '../en', 'value' => 'Hello']],
    'missing value' => [['group' => 'messages', 'key' => 'hello', 'locale' => 'en']],
    'non-string value' => [['group' => 'messages', 'key' => 'hello', 'locale' => 'en', 'value' => null]],
])->throws(ValidationException::class);

it('rejects duplicate identities across a snapshot', function (): void {
    $entry = ['group' => 'messages', 'key' => 'hello', 'locale' => 'en', 'value' => 'Hello'];

    app(RemoteTranslationSnapshot::class)->validate([$entry, $entry]);
})->throws(ValidationException::class);
