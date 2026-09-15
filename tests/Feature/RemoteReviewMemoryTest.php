<?php

use KeypointSolutions\LaravelVox\Models\VoxEnvironment;
use KeypointSolutions\LaravelVox\Models\VoxRemoteTranslation;
use KeypointSolutions\LaravelVox\Translation\RemoteReconciliation;
use KeypointSolutions\LaravelVox\Translation\RemoteTranslationSnapshot;

it('renders a page of a large remote snapshot within bounded memory', function (): void {
    config()->set('vox.translate.locales.mode', 'configured');
    config()->set('vox.translate.locales.values', ['en']);
    config()->set('vox.translate.base_locale', 'en');
    $environment = VoxEnvironment::query()->create([
        'name' => 'Staging', 'type' => 'staging', 'url' => 'https://staging.example.test', 'secret_key' => 'test',
    ]);

    for ($batch = 0; $batch < 100; $batch++) {
        $entries = [];
        for ($index = 0; $index < 500; $index++) {
            $key = 'key_'.($batch * 500 + $index);
            $entries[] = [
                'environment_id' => $environment->id,
                'identity' => RemoteTranslationSnapshot::identity('messages', $key, 'en'),
                'group' => 'messages', 'key' => $key, 'locale' => 'en', 'remote_value' => 'Remote wording',
            ];
        }
        VoxRemoteTranslation::query()->insert($entries);
    }

    memory_reset_peak_usage();
    $baseline = memory_get_usage(true);
    $page = app(RemoteReconciliation::class)->page(['environment_id' => $environment->id]);
    $extraMemory = memory_get_peak_usage(true) - $baseline;

    expect($page['total'])->toBe(50000)
        ->and($page['data'])->toHaveCount(25)
        ->and($extraMemory)->toBeLessThan(64 * 1024 * 1024);

    $lastPage = app(RemoteReconciliation::class)->page(['environment_id' => $environment->id], 99999);
    expect($lastPage['current_page'])->toBe(2000)
        ->and($lastPage['data'])->toHaveCount(25)
        ->and($lastPage['data'][0]['key'])->toBe('key_49975')
        ->and($lastPage['selection_token'])->toBe($page['selection_token']);

    $filtered = app(RemoteReconciliation::class)->page([
        'environment_id' => $environment->id, 'search' => 'key_49999',
    ]);
    expect($filtered['total'])->toBe(1)
        ->and($filtered['data'][0]['key'])->toBe('key_49999')
        ->and($filtered['counts']['incoming'])->toBe(50000)
        ->and($filtered['actionable_count'])->toBe(1);
});
