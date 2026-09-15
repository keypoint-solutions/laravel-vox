<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use KeypointSolutions\LaravelVox\Models\VoxAudit;
use KeypointSolutions\LaravelVox\Models\VoxEnvironment;
use KeypointSolutions\LaravelVox\Models\VoxRemoteTranslation;
use KeypointSolutions\LaravelVox\Models\VoxTranslation;
use KeypointSolutions\LaravelVox\Translation\RemoteReconciliation;
use KeypointSolutions\LaravelVox\Translation\TranslationDatabaseSynchronizer;
use KeypointSolutions\LaravelVox\Translation\TranslationFileRepository;
use KeypointSolutions\LaravelVox\Translation\TranslationPublisher;

beforeEach(function (): void {
    $this->reconciliationRoot = base_path('tests/.tmp/reconciliation-'.Str::uuid());
    File::ensureDirectoryExists($this->reconciliationRoot.'/en');
    File::put($this->reconciliationRoot.'/en/messages.php', "<?php\nreturn ['greeting' => 'Original'];\n");
    config()->set('vox.paths.lang', $this->reconciliationRoot);
    config()->set('vox.translate.locales.mode', 'configured');
    config()->set('vox.translate.locales.values', ['en']);
    config()->set('vox.translate.base_locale', 'en');
    config()->set('vox.parse.paths', []);
    config()->set('vox.frontend.manifest', $this->reconciliationRoot.'/frontend.json');
    config()->set('vox.frontend.runtime.enabled', false);

    $this->environment = VoxEnvironment::query()->create([
        'name' => 'Production', 'type' => 'production',
        'url' => 'https://production.example.test', 'secret_key' => 'secret',
    ]);
    $this->translation = VoxTranslation::factory()->approved()->withValues(['en' => 'Original'])
        ->create(['group' => 'messages', 'key' => 'greeting']);
    $this->reconciliation = app(RemoteReconciliation::class);
});

afterEach(function (): void {
    File::deleteDirectory($this->reconciliationRoot);
});

function incomingVoxValue(string $value, string $key = 'greeting'): array
{
    return ['group' => 'messages', 'key' => $key, 'locale' => 'en', 'value' => $value];
}

function pullVoxValues(object $test, array $values): void
{
    $test->reconciliation->ingest($test->environment->fresh(), $values);
}

function decideVoxValues(object $test, string $action, array $extra = []): int
{
    $review = $test->reconciliation->page(['environment_id' => $test->environment->id, 'state' => 'all']);

    return $test->reconciliation->resolve(array_merge([
        'action' => $action,
        'all_matching' => true,
        'filters' => $review['filters'],
        'selection_token' => $review['selection_token'],
    ], $extra));
}

it('pulls candidates without changing local values, approval, or language files', function (): void {
    $before = File::get($this->reconciliationRoot.'/en/messages.php');
    pullVoxValues($this, [incomingVoxValue('Production wording')]);

    expect($this->translation->fresh()->status)->toBe('approved')
        ->and($this->translation->values()->first()->value)->toBe('Original')
        ->and(File::get($this->reconciliationRoot.'/en/messages.php'))->toBe($before)
        ->and($this->reconciliation->page(['state' => 'all'])['data'][0]['state'])->toBe('conflict');
});

it('classifies incoming outgoing conflicting and matching values against the acknowledged baseline', function (): void {
    pullVoxValues($this, [incomingVoxValue('Original')]);
    expect($this->reconciliation->page(['state' => 'all'])['data'][0]['state'])->toBe('reconciled');

    pullVoxValues($this, [incomingVoxValue('Remote edit')]);
    expect($this->reconciliation->page(['state' => 'all'])['data'][0]['state'])->toBe('incoming');

    $this->translation->values()->first()->update(['value' => 'Local edit']);
    expect($this->reconciliation->page(['state' => 'all'])['data'][0]['state'])->toBe('conflict');

    decideVoxValues($this, 'keep');
    pullVoxValues($this, [incomingVoxValue('Remote edit')]);
    expect($this->reconciliation->page(['state' => 'all'])['data'][0]['state'])->toBe('kept')
        ->and($this->reconciliation->unresolvedCount())->toBe(0);

    expect($this->reconciliation->page(['state' => 'kept'])['actionable_count'])->toBe(0);
    $this->translation->values()->first()->update(['value' => 'Later local edit']);
    expect($this->reconciliation->page(['state' => 'outgoing'])['total'])->toBe(1);
    $this->translation->values()->first()->update(['value' => 'Local edit']);

    pullVoxValues($this, [incomingVoxValue('Another remote edit')]);
    expect($this->reconciliation->page(['state' => 'all'])['data'][0]['state'])->toBe('incoming');
});

it('does not advance the baseline on repeated unresolved pulls', function (): void {
    pullVoxValues($this, [incomingVoxValue('Original')]);
    pullVoxValues($this, [incomingVoxValue('Remote edit')]);
    pullVoxValues($this, [incomingVoxValue('Remote edit')]);

    expect($this->reconciliation->unresolvedCount())->toBe(1);
    $row = $this->reconciliation->page(['state' => 'all'])['data'][0];
    expect($row['base_value'])->toBe('Original')->and($row['state'])->toBe('incoming');
});

it('keeps accepted values through local sync and writes them only after approval and publish', function (): void {
    pullVoxValues($this, [incomingVoxValue('Accepted wording'), incomingVoxValue('Brand new', 'new_key')]);
    expect(decideVoxValues($this, 'accept'))->toBe(2);

    app(TranslationDatabaseSynchronizer::class)->sync();
    expect($this->translation->fresh()->status)->toBe('approved')
        ->and($this->translation->values()->first()->value)->toBe('Accepted wording')
        ->and(VoxTranslation::query()->where('key', 'new_key')->first()->is_orphan)->toBeFalse()
        ->and((require $this->reconciliationRoot.'/en/messages.php')['greeting'])->toBe('Original');

    app(TranslationPublisher::class)->publish();
    expect((require $this->reconciliationRoot.'/en/messages.php')['greeting'])->toBe('Accepted wording');

    VoxTranslation::query()->update(['status' => 'approved']);
    app(TranslationPublisher::class)->publish();
    expect(require $this->reconciliationRoot.'/en/messages.php')
        ->toMatchArray(['greeting' => 'Accepted wording', 'new_key' => 'Brand new'])
        ->and($this->translation->values()->first()->is_pending_publish)->toBeFalse();
});

it('resolves every matching value across pages in one audited bulk operation', function (): void {
    $values = [];
    for ($index = 0; $index < 1205; $index++) {
        $values[] = incomingVoxValue('Expected '.$index, 'batch_'.$index);
    }
    pullVoxValues($this, $values);
    $review = $this->reconciliation->page(['environment_id' => $this->environment->id, 'state' => 'incoming']);
    expect($review['data'])->toHaveCount(25)->and($review['total'])->toBe(1205);

    expect($this->reconciliation->resolve([
        'action' => 'accept', 'all_matching' => true,
        'filters' => $review['filters'], 'selection_token' => $review['selection_token'],
    ]))->toBe(1205);
    expect(VoxTranslation::query()->where('key', 'like', 'batch_%')->count())->toBe(1205)
        ->and(VoxAudit::query()->where('action', 'remote-reconciliation')->count())->toBe(1)
        ->and($this->reconciliation->unresolvedCount())->toBe(0);
});

it('rejects a stale bulk decision atomically when a local value changes', function (): void {
    pullVoxValues($this, [incomingVoxValue('Remote edit'), incomingVoxValue('New value', 'new_key')]);
    $review = $this->reconciliation->page(['state' => 'all']);
    $this->translation->values()->first()->update(['value' => 'Newer local edit']);

    expect(fn () => $this->reconciliation->resolve([
        'action' => 'accept', 'all_matching' => true,
        'filters' => $review['filters'], 'selection_token' => $review['selection_token'],
    ]))->toThrow(ValidationException::class);
    expect($this->translation->values()->first()->value)->toBe('Newer local edit')
        ->and(VoxTranslation::query()->where('key', 'new_key')->exists())->toBeFalse();
});

it('rejects a stale individual decision after another pull', function (): void {
    pullVoxValues($this, [incomingVoxValue('First remote edit')]);
    $row = $this->reconciliation->page(['state' => 'all'])['data'][0];
    pullVoxValues($this, [incomingVoxValue('Second remote edit')]);

    expect(fn () => $this->reconciliation->resolve([
        'action' => 'accept', 'entries' => [['id' => $row['id'], 'token' => $row['token']]],
    ]))->toThrow(ValidationException::class);
});

it('keeps environment baselines independent and treats absent remote entries as unavailable', function (): void {
    pullVoxValues($this, [incomingVoxValue('Original')]);
    $other = VoxEnvironment::query()->create([
        'name' => 'Staging', 'type' => 'staging', 'url' => 'https://staging.example.test', 'secret_key' => 'other',
    ]);
    $this->reconciliation->ingest($other, [incomingVoxValue('Staging wording')]);
    pullVoxValues($this, []);

    expect($this->reconciliation->page(['environment_id' => $this->environment->id, 'state' => 'all'])['data'][0]['state'])
        ->toBe('unavailable')
        ->and($this->reconciliation->page(['environment_id' => $other->id, 'state' => 'all'])['data'][0]['state'])
        ->toBe('conflict')
        ->and($this->translation->values()->first()->value)->toBe('Original');
});

it('approves edited wording without publishing and audits the edit', function (): void {
    pullVoxValues($this, [incomingVoxValue('Remote')]);
    $row = $this->reconciliation->page(['state' => 'all'])['data'][0];
    $this->reconciliation->resolve([
        'action' => 'edit', 'value' => 'Merged wording',
        'entries' => [['id' => $row['id'], 'token' => $row['token']]],
    ]);

    expect($this->translation->values()->first()->value)->toBe('Merged wording')
        ->and($this->translation->fresh()->status)->toBe('approved')
        ->and($this->translation->values()->first()->is_pending_publish)->toBeTrue()
        ->and((require $this->reconciliationRoot.'/en/messages.php')['greeting'])->toBe('Original')
        ->and(VoxAudit::query()->where('action', 'remote-reconciliation')->latest('id')->first()->context['decision'])->toBe('edit')
        ->and($this->reconciliation->unresolvedCount())->toBe(0);

    pullVoxValues($this, [incomingVoxValue('Changed'), incomingVoxValue('New', 'other')]);
    expect(fn () => decideVoxValues($this, 'edit', ['value' => 'Should not apply']))->toThrow(ValidationException::class);
});

it('rejects bulk acceptance when two selected environments propose different values for the same key', function (): void {
    pullVoxValues($this, [incomingVoxValue('Production wording')]);
    $other = VoxEnvironment::query()->create([
        'name' => 'Staging', 'type' => 'staging', 'url' => 'https://staging.example.test', 'secret_key' => 'other',
    ]);
    $this->reconciliation->ingest($other, [incomingVoxValue('Staging wording')]);
    $review = $this->reconciliation->page(['state' => 'review']);

    expect(fn () => $this->reconciliation->resolve([
        'action' => 'accept', 'all_matching' => true,
        'filters' => $review['filters'], 'selection_token' => $review['selection_token'],
    ]))->toThrow(ValidationException::class);
    expect($this->translation->values()->first()->value)->toBe('Original');
});

it('does not let delayed pulls replace a newer remote snapshot', function (): void {
    $outdatedEnvironment = $this->environment->fresh();
    pullVoxValues($this, [incomingVoxValue('Latest remote edit')]);

    expect(fn () => $this->reconciliation->ingest($outdatedEnvironment, [incomingVoxValue('Older edit')]))
        ->toThrow(ValidationException::class);
    expect(VoxRemoteTranslation::query()->first()->remote_value)->toBe('Latest remote edit');
});

it('preserves the orphan boundary when accepting a previously orphaned key', function (): void {
    $this->translation->update(['is_orphan' => true]);
    File::delete($this->reconciliationRoot.'/en/messages.php');
    pullVoxValues($this, [incomingVoxValue('Remote orphan')]);
    decideVoxValues($this, 'accept');
    $this->translation->refresh()->update(['status' => 'approved']);
    app(TranslationDatabaseSynchronizer::class)->sync();
    app(TranslationPublisher::class)->publish();

    expect($this->translation->fresh()->is_orphan)->toBeTrue()
        ->and(File::exists($this->reconciliationRoot.'/en/messages.php'))->toBeFalse();
});

it('retains accepted values after archive preview and requires publication in the deployment check', function (): void {
    pullVoxValues($this, [incomingVoxValue('Accepted')]);
    decideVoxValues($this, 'accept');
    expect($this->reconciliation->unresolvedCount())->toBe(0)
        ->and($this->reconciliation->unpublishedCount())->toBe(1);
    $this->translation->refresh()->update(['status' => 'approved']);
    $preview = $this->reconciliationRoot.'/preview';
    File::ensureDirectoryExists($preview);
    app(TranslationPublisher::class)->publishTo($preview);
    expect($this->translation->values()->first()->is_pending_publish)->toBeTrue();
    app(TranslationPublisher::class)->publish();
    expect($this->reconciliation->unpublishedCount())->toBe(0);
});

it('rejects duplicate and unsafe snapshot identities before storing any candidates', function (): void {
    expect(fn () => pullVoxValues($this, [incomingVoxValue('One'), incomingVoxValue('Two')]))
        ->toThrow(ValidationException::class);
    expect(fn () => pullVoxValues($this, [incomingVoxValue('One'), [
        'group' => '../../escape', 'key' => 'bad', 'locale' => 'en', 'value' => 'Unsafe',
    ]]))->toThrow(ValidationException::class);
    expect(VoxRemoteTranslation::query()->count())->toBe(0);
});

it('can reject all filtered incoming changes without rejecting conflicts or changing local approvals', function (): void {
    pullVoxValues($this, [incomingVoxValue('Original')]);
    VoxTranslation::factory()->withValues(['en' => 'Local conflict'])
        ->create(['group' => 'messages', 'key' => 'conflict']);
    pullVoxValues($this, [
        incomingVoxValue('Incoming edit'), incomingVoxValue('New value', 'new_key'), incomingVoxValue('Remote conflict', 'conflict'),
    ]);
    $review = $this->reconciliation->page(['state' => 'incoming']);
    expect($this->reconciliation->resolve([
        'action' => 'keep', 'all_matching' => true,
        'filters' => $review['filters'], 'selection_token' => $review['selection_token'],
    ]))->toBe(2);
    expect($this->translation->fresh()->status)->toBe('approved')
        ->and($this->translation->values()->first()->value)->toBe('Original')
        ->and(VoxTranslation::query()->where('key', 'new_key')->exists())->toBeFalse()
        ->and($this->reconciliation->unresolvedCount())->toBe(1);
});

it('publishes accepted PHP and JSON namespaces without crossing translation identities', function (): void {
    pullVoxValues($this, [
        ['group' => 'acme::messages', 'key' => 'nested.greeting', 'locale' => 'en', 'value' => 'Vendor PHP'],
        ['group' => 'json', 'key' => 'acme::Greeting', 'locale' => 'en', 'value' => 'Vendor JSON'],
        ['group' => 'json', 'key' => 'Greeting', 'locale' => 'en', 'value' => 'App JSON'],
    ]);
    decideVoxValues($this, 'accept');
    VoxTranslation::query()->update(['status' => 'approved']);
    app(TranslationPublisher::class)->publish();
    $files = app(TranslationFileRepository::class);
    expect($files->loadGroup('en', 'acme::messages'))->toHaveKey('nested.greeting', 'Vendor PHP')
        ->and($files->loadJson('en', 'acme'))->toBe(['Greeting' => 'Vendor JSON'])
        ->and($files->loadJson('en'))->toBe(['Greeting' => 'App JSON'])
        ->and($this->reconciliation->unpublishedCount())->toBe(0);
});

it('does not partially accept a batch containing an unconfigured locale', function (): void {
    pullVoxValues($this, [incomingVoxValue('Acceptable'), [
        'group' => 'messages', 'key' => 'greeting', 'locale' => 'de', 'value' => 'German value',
    ]]);
    expect(fn () => decideVoxValues($this, 'accept'))->toThrow(ValidationException::class);
    expect($this->translation->values()->first()->value)->toBe('Original')
        ->and($this->translation->values()->count())->toBe(1);
});

it('provides the configured default language reference separately from the comparison baseline', function (): void {
    config()->set('vox.translate.locales.values', ['en', 'fr']);
    config()->set('vox.translate.base_locale', 'fr');
    $this->translation->values()->create(['locale' => 'fr', 'value' => 'Référence française']);
    pullVoxValues($this, [incomingVoxValue('Incoming English')]);
    $row = $this->reconciliation->page(['state' => 'all'])['data'][0];
    expect($row['default_locale'])->toBe('fr')
        ->and($row['default_value'])->toBe('Référence française');
    $this->translation->values()->where('locale', 'fr')->delete();
    expect($this->reconciliation->page(['state' => 'all'])['data'][0]['default_value'])->toBeNull();
});

it('refreshes stale file comparisons matching a published override without changing shipped defaults or drafts', function (): void {
    $value = $this->translation->values()->first();
    $value->update(['value' => 'Published', 'file_value' => 'Original', 'published_override' => 'Published', 'is_pending_publish' => false]);
    $this->reconciliation->ingestFileValue($this->translation, 'en', 'Original', 'Original');
    File::put($this->reconciliationRoot.'/en/messages.php', "<?php return ['greeting' => 'Published'];");
    app(TranslationDatabaseSynchronizer::class)->sync();
    $row = $this->reconciliation->page(['environment_id' => -1, 'state' => 'all'])['data'][0];
    expect($row['state'])->toBe('reconciled')->and($row['remote_value'])->toBe('Published')
        ->and($value->fresh()->file_value)->toBe('Original');
    app(TranslationDatabaseSynchronizer::class)->sync();
    expect($this->reconciliation->page(['environment_id' => -1, 'state' => 'all'])['data'][0]['revision'])->toBe($row['revision']);
    $value->refresh()->saveDraft('Next draft');
    app(TranslationDatabaseSynchronizer::class)->sync();
    expect($value->fresh()->value)->toBe('Next draft')->and($value->fresh()->is_pending_publish)->toBeTrue();
});
