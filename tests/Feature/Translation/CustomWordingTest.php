<?php

use Illuminate\Support\Facades\File;
use KeypointSolutions\LaravelVox\Models\VoxRemoteTranslation;
use KeypointSolutions\LaravelVox\Models\VoxTranslation;
use KeypointSolutions\LaravelVox\Models\VoxTranslationValue;
use KeypointSolutions\LaravelVox\Support\VoxConfig;
use KeypointSolutions\LaravelVox\Translation\Publishing\TranslationDeployment;
use KeypointSolutions\LaravelVox\Translation\Publishing\TranslationPublisher;
use KeypointSolutions\LaravelVox\Translation\Remote\RemoteReconciliation;
use KeypointSolutions\LaravelVox\Translation\Sync\TranslationDatabaseSynchronizer;

beforeEach(function (): void {
    $root = prepareVoxFixtures();
    File::deleteDirectory($root.'/lang');
    File::ensureDirectoryExists($root.'/lang/en');
    File::put($root.'/lang/en/messages.php', "<?php\nreturn ['greeting' => 'Shipped greeting'];\n");
    config()->set('vox.parse.paths', []);
    config()->set('vox.retained_keys', []);
    config()->set('vox.frontend.manifest', $root.'/frontend.json');
    config()->set('vox.frontend.runtime.enabled', false);
    config()->set('vox.deployment.lock_path', $root.'/deployment.lock');
    config()->set('vox.publishing.retain_custom_wording', true);
    app(TranslationDeployment::class)->deploy();
    $this->value = VoxTranslation::query()->where('key', 'greeting')->firstOrFail()->values()->firstOrFail();
});

function greetingFile(): string
{
    return (require test()->fixtureRoot.'/lang/en/messages.php')['greeting'];
}

it('does not mark wording as custom when the approved text equals the default', function (): void {
    $this->value->saveDraft('Shipped greeting', true);
    app(TranslationPublisher::class)->publish([$this->value->id]);

    expect($this->value->fresh()->published_override)->toBeNull()
        ->and($this->value->fresh()->is_pending_publish)->toBeFalse()
        ->and(greetingFile())->toBe('Shipped greeting');
});

it('clears the custom wording marker when the default text is published again', function (): void {
    $this->value->saveDraft('Custom greeting', true);
    app(TranslationPublisher::class)->publish([$this->value->id]);
    expect($this->value->fresh()->published_override)->toBe('Custom greeting');

    $this->value->refresh()->saveDraft('Shipped greeting', true);
    app(TranslationPublisher::class)->publish([$this->value->id]);

    expect($this->value->fresh()->published_override)->toBeNull()
        ->and($this->value->fresh()->value)->toBe('Shipped greeting')
        ->and($this->value->fresh()->hasApprovedChange())->toBeFalse()
        ->and(greetingFile())->toBe('Shipped greeting');
});

it('keeps custom wording and surfaces a release that changes the default for review', function (): void {
    $this->value->saveDraft('Custom greeting', true);
    app(TranslationPublisher::class)->publish([$this->value->id]);

    File::put($this->fixtureRoot.'/lang/en/messages.php', "<?php\nreturn ['greeting' => 'New shipped greeting'];\n");
    app(TranslationDeployment::class)->deploy();

    $candidate = VoxRemoteTranslation::query()->whereNull('environment_id')->where('key', 'greeting')->where('locale', 'en')->firstOrFail();
    $review = app(RemoteReconciliation::class)->page(['environment_id' => -1, 'state' => 'review']);

    expect($this->value->fresh()->published_override)->toBe('Custom greeting')
        ->and($this->value->fresh()->file_value)->toBe('New shipped greeting')
        ->and($this->value->fresh()->value)->toBe('Custom greeting')
        ->and(greetingFile())->toBe('Custom greeting')
        ->and($candidate->remote_value)->toBe('New shipped greeting')
        ->and($candidate->base_value)->toBe('Shipped greeting')
        ->and($candidate->remote_present)->toBeTrue()
        ->and($review['total'])->toBe(1)
        ->and($review['data'][0]['state'])->toBe('conflict')
        ->and($review['data'][0]['local_value'])->toBe('Custom greeting');
});

it('keeps a default change under review through later plain syncs until it is accepted', function (): void {
    $this->value->saveDraft('Custom greeting', true);
    app(TranslationPublisher::class)->publish([$this->value->id]);
    File::put($this->fixtureRoot.'/lang/en/messages.php', "<?php\nreturn ['greeting' => 'New shipped greeting'];\n");
    app(TranslationDeployment::class)->deploy();

    app(TranslationDatabaseSynchronizer::class)->sync();
    app(TranslationDatabaseSynchronizer::class)->sync();

    $candidate = VoxRemoteTranslation::query()->whereNull('environment_id')->where('key', 'greeting')->where('locale', 'en')->firstOrFail();
    $reconciliation = app(RemoteReconciliation::class);
    $review = $reconciliation->page(['environment_id' => -1, 'state' => 'review']);
    expect($candidate->remote_value)->toBe('New shipped greeting')
        ->and($candidate->base_value)->toBe('Shipped greeting')
        ->and($review['total'])->toBe(1)
        ->and($review['data'][0]['state'])->toBe('conflict')
        ->and(greetingFile())->toBe('Custom greeting')
        ->and($this->value->fresh()->published_override)->toBe('Custom greeting');

    expect($reconciliation->resolve([
        'action' => 'accept', 'all_matching' => true, 'publish' => true,
        'filters' => $review['filters'], 'selection_token' => $review['selection_token'],
    ]))->toBe(1);

    expect($this->value->fresh()->published_override)->toBeNull()
        ->and($this->value->fresh()->value)->toBe('New shipped greeting')
        ->and($this->value->fresh()->is_pending_publish)->toBeFalse()
        ->and(greetingFile())->toBe('New shipped greeting')
        ->and($reconciliation->page(['environment_id' => -1, 'state' => 'review'])['total'])->toBe(0);
});

it('does not record a review candidate when a release re-ships the old default under custom wording', function (): void {
    $this->value->saveDraft('Custom greeting', true);
    app(TranslationPublisher::class)->publish([$this->value->id]);

    File::put($this->fixtureRoot.'/lang/en/messages.php', "<?php\nreturn ['greeting' => 'Shipped greeting'];\n");
    app(TranslationDeployment::class)->deploy();

    expect($this->value->fresh()->published_override)->toBe('Custom greeting')
        ->and($this->value->fresh()->file_value)->toBe('Shipped greeting')
        ->and(greetingFile())->toBe('Custom greeting')
        ->and(VoxRemoteTranslation::query()->whereNull('environment_id')->exists())->toBeFalse();
});

it('keeps open review selections valid when a custom wording marker is cleared', function (): void {
    $this->value->saveDraft('Custom greeting', true);
    app(TranslationPublisher::class)->publish([$this->value->id]);
    File::put($this->fixtureRoot.'/lang/en/messages.php', "<?php\nreturn ['greeting' => 'Hand edited greeting'];\n");
    app(TranslationDatabaseSynchronizer::class)->sync();

    $reconciliation = app(RemoteReconciliation::class);
    $review = $reconciliation->page(['environment_id' => -1, 'state' => 'review']);
    expect($review['total'])->toBe(1);

    VoxTranslationValue::query()->whereKey($this->value->id)->update(['published_override' => null]);

    expect($reconciliation->resolve([
        'action' => 'keep', 'all_matching' => true,
        'filters' => $review['filters'], 'selection_token' => $review['selection_token'],
    ]))->toBe(1);
});

describe('with custom wording retention off', function (): void {
    beforeEach(function (): void {
        config()->set('vox.publishing.retain_custom_wording', false);
    });

    it('publishes without marking custom wording and records the published text as the default', function (): void {
        $this->value->saveDraft('Developer greeting', true);
        app(TranslationPublisher::class)->publish([$this->value->id]);

        expect($this->value->fresh()->published_override)->toBeNull()
            ->and($this->value->fresh()->file_value)->toBe('Developer greeting')
            ->and($this->value->fresh()->hasApprovedChange())->toBeFalse()
            ->and(greetingFile())->toBe('Developer greeting');
    });

    it('treats a hand edited file as the default without rewriting it', function (): void {
        File::put($this->fixtureRoot.'/lang/en/messages.php', "<?php\nreturn ['greeting' => 'Hand edited greeting'];\n");
        app(TranslationDatabaseSynchronizer::class)->sync();

        expect($this->value->fresh()->file_value)->toBe('Hand edited greeting')
            ->and($this->value->fresh()->published_override)->toBeNull()
            ->and(greetingFile())->toBe('Hand edited greeting');
    });

    it('clears a pre-existing marker on sync and keeps the file', function (string $file): void {
        $this->value->forceFill(['value' => 'Custom greeting', 'published_override' => 'Custom greeting', 'is_pending_publish' => false])->save();
        File::put($this->fixtureRoot.'/lang/en/messages.php', "<?php\nreturn ['greeting' => '{$file}'];\n");

        app(TranslationDatabaseSynchronizer::class)->sync();

        expect($this->value->fresh()->published_override)->toBeNull()
            ->and($this->value->fresh()->file_value)->toBe($file)
            ->and(greetingFile())->toBe($file);
    })->with(['file matches the marker' => 'Custom greeting', 'file differs from the marker' => 'Hand edited greeting']);
});

it('resolves custom wording retention from the environment when unset', function (string $environment, bool $retains): void {
    config()->set('vox.publishing.retain_custom_wording', null);
    app()->detectEnvironment(fn () => $environment);

    expect(VoxConfig::retainsCustomWording())->toBe($retains);
})->with([
    'local' => ['local', false],
    'production' => ['production', true],
    'staging' => ['staging', true],
]);

it('lets the configured value override the environment', function (mixed $configured, bool $retains): void {
    config()->set('vox.publishing.retain_custom_wording', $configured);
    app()->detectEnvironment(fn () => 'local');

    expect(VoxConfig::retainsCustomWording())->toBe($retains);
})->with([[true, true], [false, false], ['true', true], ['false', false]]);
