<?php

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use KeypointSolutions\LaravelVox\Models\VoxAudit;
use KeypointSolutions\LaravelVox\Models\VoxEnvironment;
use KeypointSolutions\LaravelVox\Models\VoxRemoteTranslation;
use KeypointSolutions\LaravelVox\Models\VoxTranslation;
use KeypointSolutions\LaravelVox\Translation\RemoteReconciliation;
use KeypointSolutions\LaravelVox\Translation\RemoteTranslationSnapshot;

beforeEach(function (): void {
    $this->withoutVite();
    $this->withoutMiddleware(PreventRequestForgery::class);
    app()->detectEnvironment(fn () => 'local');
    config()->set('vox.system.bypass_auth_in_local', true);
    config()->set('vox.translate.locales.mode', 'configured');
    config()->set('vox.translate.locales.values', ['en']);
    config()->set('vox.translate.base_locale', 'en');
    config()->set('vox.sync.key', 'snapshot-key');
    $this->snapshotRoot = base_path('tests/.tmp/snapshot-'.Str::uuid());
    File::ensureDirectoryExists($this->snapshotRoot);
    config()->set('vox.paths.lang', $this->snapshotRoot);
    $this->remote = VoxEnvironment::query()->create([
        'name' => 'Production', 'type' => 'production',
        'url' => 'https://production.example.test/vox/sync', 'secret_key' => 'snapshot-key',
    ]);
    $this->payload = ['format' => RemoteTranslationSnapshot::FORMAT, 'values' => [
        ['group' => 'messages', 'key' => 'greeting', 'locale' => 'en', 'value' => 'Remote wording'],
    ]];
});

afterEach(function (): void {
    File::deleteDirectory($this->snapshotRoot);
});

it('exports current database wording through the keyed endpoint including unpublished edits', function (): void {
    VoxTranslation::factory()->withValues(['en' => 'Unpublished admin edit'])->create([
        'group' => 'messages', 'key' => 'greeting', 'status' => 'pending',
    ]);
    VoxTranslation::factory()->withValues(['en' => 'Orphan'])->create([
        'group' => 'messages', 'key' => 'orphan', 'is_orphan' => true,
    ]);

    $this->postJson('/vox/sync')->assertForbidden();
    $this->withHeader('X-Vox-Key', 'snapshot-key')->postJson('/vox/sync')
        ->assertOk()->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('format', RemoteTranslationSnapshot::FORMAT)
        ->assertJsonCount(1, 'values')
        ->assertJsonPath('values.0.value', 'Unpublished admin edit');
    config()->set('vox.sync.enabled', false);
    $this->postJson('/vox/sync')->assertNotFound();
});

it('pulls a database snapshot into the filtered review page and keeps secrets out of props', function (): void {
    Http::fake([$this->remote->url => Http::response($this->payload)]);
    $this->from('/vox/sync')->post("/vox/sync/environments/{$this->remote->id}/pull")
        ->assertRedirect('/vox/sync');
    expect(VoxTranslation::query()->count())->toBe(0)->and(File::allFiles($this->snapshotRoot))->toBeEmpty();
    $this->get('/vox/sync?environment_id='.$this->remote->id.'&state=incoming')
        ->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Sync', false)
        ->where('reconciliation.total', 1)
        ->where('reconciliation.data.0.remote_value', 'Remote wording')
        ->where('reconciliation.data.0.state', 'incoming')
        ->where('vox.routes.sync_reconcile', '/vox/sync/reconcile')
        ->missing('environments.0.secret_key'));
    Http::assertSent(fn ($request): bool => $request->hasHeader('Accept', 'application/json')
        && $request->hasHeader('X-Vox-Key', 'snapshot-key'));
});

it('accepts selected candidates through the protected UI route and rejects replay', function (): void {
    $service = app(RemoteReconciliation::class);
    $service->ingest($this->remote, $this->payload['values']);
    $row = $service->page()['data'][0];
    $decision = ['action' => 'accept', 'entries' => [['id' => $row['id'], 'token' => $row['token']]]];

    config()->set('vox.system.bypass_auth_in_local', false);
    $this->postJson('/vox/sync/reconcile', $decision)->assertForbidden();
    config()->set('vox.system.bypass_auth_in_local', true);
    $this->from('/vox/sync')->post('/vox/sync/reconcile', $decision)->assertRedirect('/vox/sync');
    expect(VoxTranslation::query()->first()->status)->toBe('pending')
        ->and(VoxTranslation::query()->first()->values()->first()->value)->toBe('Remote wording')
        ->and(VoxAudit::query()->where('action', 'remote-reconciliation')->count())->toBe(1);
    $this->postJson('/vox/sync/reconcile', $decision)->assertUnprocessable();
});

it('rejects malformed snapshots without replacing the last successful remote observation', function (): void {
    app(RemoteReconciliation::class)->ingest($this->remote, $this->payload['values']);
    Http::fake([$this->remote->url => Http::response(['format' => RemoteTranslationSnapshot::FORMAT, 'values' => [
        ['group' => 'messages', 'key' => 'bad', 'locale' => '../../outside', 'value' => 'bad'],
    ]])]);

    $this->from('/vox/sync')->post("/vox/sync/environments/{$this->remote->id}/pull")
        ->assertSessionHasErrors('sync');
    expect(VoxRemoteTranslation::query()->first()->remote_value)->toBe('Remote wording')
        ->and(VoxRemoteTranslation::query()->first()->remote_present)->toBeTrue();
});

it('resets comparison history when an environment endpoint changes', function (): void {
    app(RemoteReconciliation::class)->ingest($this->remote, $this->payload['values']);
    $this->put('/vox/sync/environments/'.$this->remote->id, [
        'name' => 'Production', 'type' => 'production', 'url' => 'https://other.example.test', 'secret_key' => '',
    ])->assertRedirect();
    expect(VoxRemoteTranslation::query()->count())->toBe(0)
        ->and($this->remote->fresh()->last_pulled_at)->toBeNull();
});

it('provides a non-interactive deployment check that fails on unresolved incoming changes', function (): void {
    Http::fake([$this->remote->url => Http::response($this->payload)]);
    $this->artisan('vox:sync-remote', ['--environment' => $this->remote->id, '--check' => true, '--no-interaction' => true])
        ->assertFailed();
    expect(VoxRemoteTranslation::query()->count())->toBe(1)
        ->and(VoxTranslation::query()->count())->toBe(0);

    VoxTranslation::factory()->withValues(['en' => 'Remote wording'])
        ->create(['group' => 'messages', 'key' => 'greeting']);
    $this->artisan('vox:sync-remote', ['--environment' => $this->remote->id, '--check' => true, '--no-interaction' => true])
        ->assertFailed();
    File::ensureDirectoryExists($this->snapshotRoot.'/en');
    File::put($this->snapshotRoot.'/en/messages.php', "<?php\nreturn ['greeting' => 'Remote wording'];\n");
    $this->artisan('vox:sync-remote', ['--environment' => $this->remote->id, '--check' => true, '--no-interaction' => true])
        ->assertSuccessful();
});
