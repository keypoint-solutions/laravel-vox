<?php

use Illuminate\Support\Facades\File;
use KeypointSolutions\LaravelVox\Models\VoxEnvironment;
use KeypointSolutions\LaravelVox\Models\VoxTranslation;
use KeypointSolutions\LaravelVox\Translation\RemoteReconciliation;
use KeypointSolutions\LaravelVox\Translation\TranslationDatabaseSynchronizer;
use KeypointSolutions\LaravelVox\Translation\TranslationPublisher;

beforeEach(function (): void {
    $root = prepareVoxFixtures();
    File::deleteDirectory($root.'/lang');
    File::ensureDirectoryExists($root.'/lang/en');
    File::ensureDirectoryExists($root.'/lang/fr');
    File::put($root.'/lang/en/messages.php', "<?php\nreturn ['greeting' => 'Hello'];\n");
    File::put($root.'/lang/fr/messages.php', "<?php\nreturn ['greeting' => 'Bonjour'];\n");
    config()->set('vox.parse.paths', []);
    config()->set('vox.retained_keys', []);
    config()->set('vox.frontend.manifest', $root.'/frontend.json');
    config()->set('vox.frontend.runtime.enabled', false);
    config()->set('vox.deployment.lock_path', $root.'/deployment.lock');
    app(TranslationDatabaseSynchronizer::class)->sync();
    $this->translation = VoxTranslation::query()->where('key', 'greeting')->firstOrFail();
});

it('retains the last published value when a manager saves a new draft', function (): void {
    $value = $this->translation->values()->where('locale', 'en')->firstOrFail();
    $value->saveDraft('Published hello', true);
    app(TranslationPublisher::class)->publish([$value->id]);
    $value->refresh()->saveDraft('Next hello');

    expect($value->fresh()->liveValue())->toBe('Published hello')
        ->and($value->fresh()->file_value)->toBe('Hello')
        ->and($value->fresh()->value)->toBe('Next hello')
        ->and($value->fresh()->is_approved)->toBeFalse()
        ->and($value->fresh()->is_pending_publish)->toBeTrue();
    app(TranslationPublisher::class)->publish();
    expect((require $this->fixtureRoot.'/lang/en/messages.php')['greeting'])->toBe('Published hello')
        ->and($value->fresh()->is_pending_publish)->toBeTrue();
});

it('publishes an approved locale without publishing its sibling draft', function (): void {
    $english = $this->translation->values()->where('locale', 'en')->firstOrFail();
    $french = $this->translation->values()->where('locale', 'fr')->firstOrFail();
    $french->saveDraft('Brouillon');
    $english->saveDraft('Approved hello', true);

    app(TranslationPublisher::class)->publish();

    expect((require $this->fixtureRoot.'/lang/en/messages.php')['greeting'])->toBe('Approved hello')
        ->and((require $this->fixtureRoot.'/lang/fr/messages.php')['greeting'])->toBe('Bonjour')
        ->and($english->fresh()->published_override)->toBe('Approved hello')
        ->and($english->fresh()->is_pending_publish)->toBeFalse()
        ->and($french->fresh()->is_pending_publish)->toBeTrue()
        ->and($french->fresh()->published_override)->toBeNull();
});

it('limits publication to the selected approved values', function (): void {
    $english = $this->translation->values()->where('locale', 'en')->firstOrFail();
    $french = $this->translation->values()->where('locale', 'fr')->firstOrFail();
    $english->saveDraft('Approved hello', true);
    $french->saveDraft('Bonjour approuvé', true);

    app(TranslationPublisher::class)->publish([$english->id]);

    expect((require $this->fixtureRoot.'/lang/fr/messages.php')['greeting'])->toBe('Bonjour')
        ->and($french->fresh()->is_pending_publish)->toBeTrue()
        ->and($french->fresh()->published_override)->toBeNull()
        ->and($english->fresh()->published_override)->toBe('Approved hello');
});

it('accepts and approves a remote locale independently from another locale draft', function (): void {
    $french = $this->translation->values()->where('locale', 'fr')->firstOrFail();
    $french->saveDraft('Brouillon');
    $environment = VoxEnvironment::query()->create([
        'name' => 'Production', 'type' => 'production',
        'url' => 'https://production.example.test', 'secret_key' => 'secret',
    ]);
    $reconciliation = app(RemoteReconciliation::class);
    $reconciliation->ingest($environment, [[
        'group' => 'messages', 'key' => 'greeting', 'locale' => 'en', 'value' => 'Remote hello',
    ]]);
    $review = $reconciliation->page(['environment_id' => $environment->id, 'state' => 'all']);
    $reconciliation->resolve([
        'action' => 'accept', 'all_matching' => true,
        'filters' => $review['filters'], 'selection_token' => $review['selection_token'],
    ]);
    $english = $this->translation->values()->where('locale', 'en')->firstOrFail();
    expect($english->is_approved)->toBeTrue()->and($english->is_pending_publish)->toBeTrue()
        ->and($french->fresh()->is_approved)->toBeFalse();
    app(TranslationPublisher::class)->publish();

    expect((require $this->fixtureRoot.'/lang/en/messages.php')['greeting'])->toBe('Remote hello')
        ->and((require $this->fixtureRoot.'/lang/fr/messages.php')['greeting'])->toBe('Bonjour')
        ->and($french->fresh()->is_pending_publish)->toBeTrue();
});

it('remembers a rejected local file candidate until that source changes again', function (): void {
    $reconciliation = app(RemoteReconciliation::class);
    File::put($this->fixtureRoot.'/lang/en/messages.php', "<?php\nreturn ['greeting' => 'Candidate hello'];\n");
    app(TranslationDatabaseSynchronizer::class)->sync();
    $review = $reconciliation->page(['environment_id' => -1, 'state' => 'all']);
    expect($review['total'])->toBe(1);
    $reconciliation->resolve([
        'action' => 'keep', 'all_matching' => true,
        'filters' => $review['filters'], 'selection_token' => $review['selection_token'],
    ]);

    app(TranslationDatabaseSynchronizer::class)->sync();
    expect($reconciliation->page(['environment_id' => -1, 'state' => 'review'])['total'])->toBe(0)
        ->and($this->translation->values()->where('locale', 'en')->firstOrFail()->value)->toBe('Hello');

    File::put($this->fixtureRoot.'/lang/en/messages.php', "<?php\nreturn ['greeting' => 'New candidate hello'];\n");
    app(TranslationDatabaseSynchronizer::class)->sync();
    expect($reconciliation->page(['environment_id' => -1, 'state' => 'review'])['total'])->toBe(1);
});

it('accepts and publishes a CLI review selection without another approval step', function (): void {
    $french = $this->translation->values()->where('locale', 'fr')->firstOrFail();
    $french->saveDraft('Unrelated draft');
    File::put($this->fixtureRoot.'/lang/en/messages.php', "<?php return ['greeting' => 'Incoming hello'];");
    app(TranslationDatabaseSynchronizer::class)->sync();

    $this->artisan('vox:review', ['--environment' => 'files', '--accept-all' => true, '--publish' => true])->assertSuccessful();

    $english = $this->translation->values()->where('locale', 'en')->firstOrFail();
    expect($english->is_approved)->toBeTrue()
        ->and($english->published_override)->toBe('Incoming hello')
        ->and($english->is_pending_publish)->toBeFalse()
        ->and($french->fresh()->is_pending_publish)->toBeTrue()
        ->and((require $this->fixtureRoot.'/lang/fr/messages.php')['greeting'])->toBe('Bonjour');
});
