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

it('exports long translation keys without truncating their identity', function (): void {
    $key = str_repeat('Long translation key ', 25);
    $translation = VoxTranslation::factory()->create(['group' => 'labels', 'key' => $key]);
    $translation->values()->create([
        'locale' => 'en', 'value' => 'Draft wording', 'file_value' => 'Published wording',
    ]);

    $snapshot = app(RemoteTranslationSnapshot::class);

    expect($snapshot->export()['values'][0]['key'])->toBe($key)
        ->and($snapshot->export(true)['values'][0]['key'])->toBe($key);
});

it('validates fifty thousand values within the snapshot processing budget', function (): void {
    $values = [];
    for ($index = 0; $index < 50000; $index++) {
        $values[] = ['group' => 'messages', 'key' => 'key_'.$index, 'locale' => 'en', 'value' => 'Published wording'];
    }

    $start = hrtime(true);
    $validated = app(RemoteTranslationSnapshot::class)->validate($values);
    $seconds = (hrtime(true) - $start) / 1e9;

    expect($validated)->toHaveCount(50000)
        ->and($seconds)->toBeLessThan(3.0);
});

it('preserves wire format validation boundaries', function (): void {
    $snapshot = app(RemoteTranslationSnapshot::class);
    $entry = ['group' => 'vendor::messages', 'key' => '0', 'locale' => 'sr_Latn_RS', 'value' => ''];

    expect($snapshot->validate([]))->toBe([])
        ->and($snapshot->validate([$entry]))->toBe([$entry]);

    $invalid = [null, 'invalid', array_fill(0, 100001, $entry), [null]];
    foreach ([
        ['group', '../messages'], ['group', str_repeat('a', 256)], ['group', 123],
        ['key', '  '], ['key', 123], ['locale', 'en/gb'], ['locale', 123],
        ['value', str_repeat('a', 1000001)], ['value', []],
    ] as [$field, $replacement]) {
        $invalid[] = [array_replace($entry, [$field => $replacement])];
    }

    foreach ($invalid as $payload) {
        expect(fn () => $snapshot->validate($payload))->toThrow(ValidationException::class);
    }
});
