<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use KeypointSolutions\LaravelVox\Models\VoxTranslation;
use KeypointSolutions\LaravelVox\Support\VoxAuditLogger;
use KeypointSolutions\LaravelVox\Support\VoxMutationLock;
use KeypointSolutions\LaravelVox\Translation\TranslationDeployment;
use KeypointSolutions\LaravelVox\Translation\TranslationPublisher;

beforeEach(function (): void {
    $root = prepareVoxFixtures();
    File::deleteDirectory($root.'/lang');
    File::ensureDirectoryExists($root.'/lang/en');
    File::put($root.'/lang/en/messages.php', "<?php\nreturn ['greeting' => 'Shipped greeting'];\n");
    config()->set('vox.parse.paths', []);
    config()->set('vox.dynamic_keys.patterns', []);
    config()->set('vox.frontend.manifest', $root.'/frontend.json');
    config()->set('vox.frontend.runtime.enabled', false);
    config()->set('vox.deployment.lock_path', $root.'/deployment.lock');
});

it('deploys shipped values as approved defaults without creating manager overrides', function (): void {
    app(TranslationDeployment::class)->deploy();

    $value = VoxTranslation::query()->where('key', 'greeting')->firstOrFail()->values()->firstOrFail();
    expect($value->value)->toBe('Shipped greeting')
        ->and($value->file_value)->toBe('Shipped greeting')
        ->and($value->published_override)->toBeNull()
        ->and($value->is_approved)->toBeTrue()
        ->and($value->is_pending_publish)->toBeFalse()
        ->and((require $this->fixtureRoot.'/lang/en/messages.php')['greeting'])->toBe('Shipped greeting');
});

it('regenerates published files without importing overrides as defaults', function (): void {
    app(TranslationDeployment::class)->deploy();
    $value = VoxTranslation::query()->where('key', 'greeting')->firstOrFail()->values()->firstOrFail();
    $value->saveDraft('Manager greeting', true);
    app(TranslationPublisher::class)->publish([$value->id]);

    $this->artisan('vox:publish', ['--published-only' => true])->assertSuccessful();
    $this->artisan('vox:publish', ['--published-only' => true])->assertSuccessful();

    expect($value->fresh()->file_value)->toBe('Shipped greeting')
        ->and($value->fresh()->published_override)->toBe('Manager greeting')
        ->and((require $this->fixtureRoot.'/lang/en/messages.php')['greeting'])->toBe('Manager greeting');
});

it('distinguishes regeneration from importing fresh defaults matching an override', function (bool $import): void {
    app(TranslationDeployment::class)->deploy();
    $value = VoxTranslation::query()->where('key', 'greeting')->firstOrFail()->values()->firstOrFail();
    $value->saveDraft('Manager greeting', true);
    app(TranslationPublisher::class)->publish([$value->id]);
    $value->refresh()->saveDraft('Unpublished edit');

    expect($value->fresh()->file_value)->toBe('Shipped greeting')
        ->and($value->fresh()->published_override)->toBe('Manager greeting')
        ->and((require $this->fixtureRoot.'/lang/en/messages.php')['greeting'])->toBe('Manager greeting');

    if ($import) {
        app(TranslationDeployment::class)->deploy();
    } else {
        app(TranslationPublisher::class)->regenerate();
    }

    expect($value->fresh()->file_value)->toBe($import ? 'Manager greeting' : 'Shipped greeting')
        ->and($value->fresh()->published_override)->toBe('Manager greeting')
        ->and($value->fresh()->value)->toBe('Unpublished edit')
        ->and($value->fresh()->is_pending_publish)->toBeTrue()
        ->and((require $this->fixtureRoot.'/lang/en/messages.php')['greeting'])->toBe('Manager greeting');
})->with(['regenerate modified files' => false, 'install matching source files' => true]);

it('deploys new release defaults immediately while retaining an override and a newer draft', function (): void {
    app(TranslationDeployment::class)->deploy();
    $value = VoxTranslation::query()->where('key', 'greeting')->firstOrFail()->values()->firstOrFail();
    $value->saveDraft('Published greeting', true);
    app(TranslationPublisher::class)->publish([$value->id]);
    $value->refresh()->saveDraft('Unapproved draft');

    File::put($this->fixtureRoot.'/lang/en/messages.php', "<?php\nreturn ['greeting' => 'New shipped greeting', 'new_phrase' => 'New shipped phrase'];\n");
    app(TranslationDeployment::class)->deploy();

    expect(require $this->fixtureRoot.'/lang/en/messages.php')->toBe([
        'greeting' => 'Published greeting', 'new_phrase' => 'New shipped phrase',
    ])->and($value->fresh()->file_value)->toBe('New shipped greeting')
        ->and($value->fresh()->value)->toBe('Unapproved draft')
        ->and($value->fresh()->is_pending_publish)->toBeTrue()
        ->and($value->fresh()->is_approved)->toBeFalse();
    $newValue = VoxTranslation::query()->where('key', 'new_phrase')->firstOrFail()->values()->firstOrFail();
    expect($newValue->is_approved)->toBeTrue()->and($newValue->published_override)->toBeNull();
});

it('rolls back database reconciliation and generated files when deployment fails', function (): void {
    app(TranslationDeployment::class)->deploy();
    $value = VoxTranslation::query()->where('key', 'greeting')->firstOrFail()->values()->firstOrFail();
    $value->saveDraft('Manager greeting', true);
    app(TranslationPublisher::class)->publish([$value->id]);
    $incoming = "<?php\nreturn ['greeting' => 'New shipped greeting', 'new_phrase' => 'New phrase'];\n";
    File::put($this->fixtureRoot.'/lang/en/messages.php', $incoming);
    $this->mock(VoxAuditLogger::class)
        ->shouldReceive('record')->once()->andThrow(new RuntimeException('Deployment audit failed'));

    expect(fn () => app(TranslationDeployment::class)->deploy())->toThrow(RuntimeException::class, 'Deployment audit failed');

    expect(File::get($this->fixtureRoot.'/lang/en/messages.php'))->toBe($incoming)
        ->and($value->fresh()->file_value)->toBe('Shipped greeting')
        ->and($value->fresh()->published_override)->toBe('Manager greeting')
        ->and(VoxTranslation::query()->where('key', 'new_phrase')->exists())->toBeFalse();
});

it('applies fresh developer defaults while keeping a draft unpublished', function (bool $draft): void {
    app(TranslationDeployment::class)->deploy();
    $value = VoxTranslation::query()->where('key', 'greeting')->firstOrFail()->values()->firstOrFail();
    if ($draft) {
        $value->saveDraft('Manager draft');
    }
    File::put($this->fixtureRoot.'/lang/en/messages.php', "<?php return ['greeting' => 'New default'];");

    app(TranslationDeployment::class)->deploy();

    expect((require $this->fixtureRoot.'/lang/en/messages.php')['greeting'])->toBe('New default')
        ->and($value->fresh()->file_value)->toBe('New default')
        ->and($value->fresh()->value)->toBe($draft ? 'Manager draft' : 'New default')
        ->and($value->fresh()->is_pending_publish)->toBe($draft)
        ->and($value->fresh()->published_override)->toBeNull();
})->with([false, true]);

it('regenerates recorded defaults and overrides without publishing approved drafts or deletions', function (): void {
    app(TranslationDeployment::class)->deploy();
    $value = VoxTranslation::query()->where('key', 'greeting')->firstOrFail()->values()->firstOrFail();
    $value->saveDraft('Approved but unpublished', true);
    $value->translation->update(['is_pending_delete' => true]);
    File::put($this->fixtureRoot.'/lang/en/messages.php', "<?php return ['greeting' => 'Unimported file edit'];");

    $this->artisan('vox:publish', ['--published-only' => true])->assertSuccessful();

    expect((require $this->fixtureRoot.'/lang/en/messages.php')['greeting'])->toBe('Shipped greeting')
        ->and($value->fresh()->file_value)->toBe('Shipped greeting')
        ->and($value->fresh()->value)->toBe('Approved but unpublished')
        ->and($value->fresh()->is_pending_publish)->toBeTrue()
        ->and($value->fresh()->translation->is_pending_delete)->toBeTrue();
});

it('restores generated manifests and existing files after a failed deployment', function (): void {
    app(TranslationDeployment::class)->deploy();
    File::put($this->fixtureRoot.'/frontend.json', '{"previous":"frontend"}');
    File::put($this->fixtureRoot.'/dynamic.json', '{"previous":"dynamic"}');
    $stale = "<?php return ['stale' => 'Old manager value'];";
    File::put($this->fixtureRoot.'/lang/en/manager.php', $stale);
    $this->mock(VoxAuditLogger::class)
        ->shouldReceive('record')->once()->andThrow(new RuntimeException('Audit failed'));

    expect(fn () => app(TranslationDeployment::class)->deploy())->toThrow(RuntimeException::class, 'Audit failed');

    expect(File::get($this->fixtureRoot.'/frontend.json'))->toBe('{"previous":"frontend"}')
        ->and(File::get($this->fixtureRoot.'/dynamic.json'))->toBe('{"previous":"dynamic"}')
        ->and(File::get($this->fixtureRoot.'/lang/en/manager.php'))->toBe($stale);
});

it('initializes an absent database when running the deployment command', function (): void {
    $connection = config('vox.database.connection');
    DB::purge($connection);
    File::delete(config('vox.database.path'));
    $this->app->usePublicPath($this->fixtureRoot.'/public');

    $this->artisan('vox:deploy')->assertSuccessful();

    expect(File::exists(config('vox.database.path')))->toBeTrue()
        ->and(VoxTranslation::query()->where('key', 'greeting')->exists())->toBeTrue();
});

it('allows the application to hold the mutation lock across preparation and activation', function (): void {
    app(VoxMutationLock::class)->run(function (): void {
        app(TranslationDeployment::class)->deploy();
        $handle = fopen($this->fixtureRoot.'/deployment.lock', 'c');
        try {
            expect(flock($handle, LOCK_EX | LOCK_NB))->toBeFalse();
        } finally {
            fclose($handle);
        }
    });
    $handle = fopen($this->fixtureRoot.'/deployment.lock', 'c');
    try {
        expect(flock($handle, LOCK_EX | LOCK_NB))->toBeTrue();
    } finally {
        fclose($handle);
    }
});

it('refuses deployment while another publisher holds the release lock', function (): void {
    $handle = fopen($this->fixtureRoot.'/deployment.lock', 'c');
    flock($handle, LOCK_EX);
    try {
        expect(fn () => app(TranslationDeployment::class)->deploy())->toThrow(RuntimeException::class, 'Vox is publishing or deploying');
        expect(VoxTranslation::query()->count())->toBe(0)
            ->and(File::exists($this->fixtureRoot.'/source'))->toBeFalse();
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
});

it('publishes approved pending wording through the publish command', function (): void {
    app(TranslationDeployment::class)->deploy();
    $value = VoxTranslation::query()->where('key', 'greeting')->firstOrFail()->values()->firstOrFail();
    $value->saveDraft('Approved manager wording', true);

    $this->artisan('vox:publish')->assertSuccessful();

    expect((require $this->fixtureRoot.'/lang/en/messages.php')['greeting'])->toBe('Approved manager wording')
        ->and($value->fresh()->published_override)->toBe('Approved manager wording')
        ->and($value->fresh()->file_value)->toBe('Shipped greeting')
        ->and($value->fresh()->is_pending_publish)->toBeFalse();
});

it('rewrites frontend artifacts from live wording without publishing newer drafts', function (): void {
    config()->set('vox.frontend.runtime.enabled', true);
    config()->set('vox.frontend.runtime.path', $this->fixtureRoot.'/runtime');
    config()->set('vox.frontend.groups.mode', 'configured');
    config()->set('vox.frontend.groups.values', ['messages']);
    app(TranslationDeployment::class)->deploy();
    $value = VoxTranslation::query()->where('key', 'greeting')->firstOrFail()->values()->firstOrFail();
    $value->saveDraft('Published wording', true);
    app(TranslationPublisher::class)->publish();
    $value->refresh()->saveDraft('Next draft', true);
    File::deleteDirectory($this->fixtureRoot.'/runtime');

    $this->artisan('vox:publish', ['--published-only' => true])->assertSuccessful();

    expect(json_decode(File::get($this->fixtureRoot.'/runtime/en.json'), true)['messages.greeting'])->toBe('Published wording')
        ->and($value->fresh()->value)->toBe('Next draft')
        ->and($value->fresh()->is_pending_publish)->toBeTrue();
});
