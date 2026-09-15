<?php

use Illuminate\Support\Facades\Http;
use KeypointSolutions\LaravelVox\Database\Factories\VoxTranslationFactory;
use KeypointSolutions\LaravelVox\Models\VoxAudit;
use KeypointSolutions\LaravelVox\Models\VoxEnvironment;
use KeypointSolutions\LaravelVox\Models\VoxRemoteTranslation;
use KeypointSolutions\LaravelVox\Translation\RemoteReconciliation;

beforeEach(function (): void {
    $this->withoutVite();
    $this->withoutVoxCsrfMiddleware();
    app()->detectEnvironment(fn () => 'local');
    config()->set('vox.system.bypass_auth_in_local', true);
    config()->set('vox.translate.driver', 'openai');
    config()->set('vox.translate.providers.openai.api_key', 'test-key');
    config()->set('vox.translate.locales.mode', 'configured');
    config()->set('vox.translate.locales.values', ['en', 'fr']);
    config()->set('vox.parse.missing_translation_prefix', '🚩');
    $environment = VoxEnvironment::query()->create([
        'name' => 'AI review', 'type' => 'production',
        'url' => 'https://review.example.test', 'secret_key' => 'secret',
    ]);
    $this->translation = VoxTranslationFactory::new()->approved()->withValues([
        'en' => 'Hello :name', 'fr' => '🚩Hello :name',
    ])->create(['group' => 'messages', 'key' => 'hello']);
    app(RemoteReconciliation::class)->ingest($environment, [[
        'group' => 'messages', 'key' => 'hello', 'locale' => 'fr', 'value' => 'Bonjour :name',
    ]]);
    $this->row = app(RemoteReconciliation::class)->page(['state' => 'review'])['data'][0];
    $this->payload = ['id' => $this->row['id'], 'token' => $this->row['token'], 'local' => 'Salut :name', 'incoming' => 'Bonjour :name !'];
});

it('chooses between the submitted drafts without saving or auditing a review decision', function (): void {
    Http::fake(['*' => Http::response(['output' => [['type' => 'message', 'content' => [[
        'type' => 'output_text', 'text' => '{"choice":"incoming","reason":"Both are French, so incoming wins."}',
    ]]]]])]);
    $before = $this->translation->values()->get()->toArray();
    $audits = VoxAudit::count();
    $this->from('/vox/sync')->post('/vox/sync/choose', $this->payload)
        ->assertRedirect('/vox/sync')
        ->assertInertiaFlash('translationChoice.choice', 'incoming');
    expect($this->translation->values()->get()->toArray())->toBe($before)
        ->and(VoxAudit::count())->toBe($audits)
        ->and(app(RemoteReconciliation::class)->reviewCandidate($this->row['id'], $this->row['token'])['token'])->toBe($this->row['token']);
    Http::assertSent(function ($request): bool {
        $input = json_decode($request['input'], true);

        return $input['local'] === 'Salut :name' && $input['incoming'] === 'Bonjour :name !'
            && $input['default_value'] === 'Hello :name' && $input['default_locale'] === 'en' && $input['locale'] === 'fr'
            && $input['missing_prefix'] === '🚩';
    });
});

it('rejects stale candidates before asking AI', function (): void {
    Http::fake();
    $this->payload['token'] = str_repeat('0', 64);
    $this->from('/vox/sync')->post('/vox/sync/choose', $this->payload)->assertSessionHasErrors();
    Http::assertNothingSent();
});

it('rejects unsupported drivers without changing wording', function (): void {
    config()->set('vox.translate.driver', 'null');
    Http::fake();
    $this->from('/vox/sync')->post('/vox/sync/choose', $this->payload)->assertSessionHasErrors('choice');
    Http::assertNothingSent();
});

it('returns a recoverable error for invalid AI output', function (): void {
    Http::fake(['*' => Http::response(['output' => [['type' => 'message', 'content' => [[
        'type' => 'output_text', 'text' => '{"choice":"merged","reason":"Use new wording"}',
    ]]]]])]);
    $this->from('/vox/sync')->post('/vox/sync/choose', $this->payload)->assertSessionHasErrors('choice');
    expect($this->translation->values()->where('locale', 'fr')->first()->value)->toBe('🚩Hello :name');
});

it('discards an AI result when the candidate changes during the request', function (): void {
    Http::fake(function () {
        $this->translation->values()->where('locale', 'fr')->update(['value' => 'Changed concurrently']);

        return Http::response(['output' => [['type' => 'message', 'content' => [[
            'type' => 'output_text', 'text' => '{"choice":"incoming","reason":"Incoming is French."}',
        ]]]]]);
    });
    $this->from('/vox/sync')->post('/vox/sync/choose', $this->payload)->assertSessionHasErrors();
    expect(VoxAudit::query()->where('action', 'remote-reconciliation')->count())->toBe(0);
});

it('chooses multiple selected drafts in one AI request', function (): void {
    $second = VoxRemoteTranslation::query()->create([
        'environment_id' => null, 'identity' => 'bulk-second', 'group' => 'messages', 'key' => 'second',
        'locale' => 'fr', 'remote_value' => 'Deuxième', 'remote_present' => true, 'has_baseline' => false, 'revision' => 1,
    ]);
    $row = collect(app(RemoteReconciliation::class)->page(['state' => 'all'])['data'])->firstWhere('id', $second->id);
    $entries = [$this->payload, ['id' => $row['id'], 'token' => $row['token'], 'local' => '', 'incoming' => 'Deuxième modifiée']];
    Http::fake(['*' => Http::response(['output' => [['type' => 'message', 'content' => [[
        'type' => 'output_text', 'text' => json_encode([
            $this->row['id'] => ['choice' => 'local', 'reason' => 'Local is complete.'],
            $second->id => ['choice' => 'incoming', 'reason' => 'Incoming is translated.'],
        ]),
    ]]]]])]);
    $this->from('/vox/sync')->post('/vox/sync/choose', ['entries' => $entries])
        ->assertRedirect('/vox/sync')->assertInertiaFlash('translationChoices.0.choice', 'local')
        ->assertInertiaFlash('translationChoices.1.choice', 'incoming');
    Http::assertSentCount(1);
    Http::assertSent(fn ($request): bool => count(json_decode($request['input'], true)) === 2);
    expect($this->translation->values()->where('locale', 'fr')->first()->value)->toBe('🚩Hello :name');
});

it('confirms edited and unchanged selections together without publishing', function (): void {
    $this->from('/vox/sync')->post('/vox/sync/reconcile', ['action' => 'confirm', 'entries' => [[
        'id' => $this->row['id'], 'token' => $this->row['token'], 'side' => 'incoming', 'value' => 'Bonjour corrigé :name',
    ]], 'publish' => false])->assertSessionHasNoErrors();
    $value = $this->translation->values()->where('locale', 'fr')->first();
    expect($value->value)->toBe('Bonjour corrigé :name')->and($value->is_approved)->toBeTrue()
        ->and($value->is_pending_publish)->toBeTrue()
        ->and(VoxAudit::where('action', 'remote-reconciliation')->latest('id')->first()->context['decisions']['edit'])->toBe(1);
});

it('rejects stale bulk confirmation before saving any selected wording', function (): void {
    $this->from('/vox/sync')->post('/vox/sync/reconcile', ['action' => 'confirm', 'entries' => [
        ['id' => $this->row['id'], 'token' => $this->row['token'], 'side' => 'incoming', 'value' => 'Edited'],
        ['id' => 999999, 'token' => str_repeat('0', 64), 'side' => 'local', 'value' => 'Other'],
    ]])->assertSessionHasErrors();
    expect($this->translation->values()->where('locale', 'fr')->first()->value)->toBe('🚩Hello :name')
        ->and(VoxAudit::where('action', 'remote-reconciliation')->count())->toBe(0);
});

it('uses the same default reference for the collapsible and AI without any English locale', function (bool $bulk, bool $missing): void {
    config()->set('vox.translate.base_locale', 'de');
    config()->set('vox.translate.locales.values', ['fr', 'de']);
    $this->translation->values()->where('locale', 'en')->delete();
    if (! $missing) {
        $this->translation->values()->create(['locale' => 'de', 'value' => 'Hallo :name']);
    }
    $row = app(RemoteReconciliation::class)->page(['state' => 'review'])['data'][0];
    expect($row['default_locale'])->toBe('de')
        ->and($row['default_value'])->toBe($missing ? null : 'Hallo :name');
    $payload = [...$this->payload, 'token' => $row['token']];
    $choice = ['choice' => 'incoming', 'reason' => 'Incoming is complete.'];
    Http::fake(['*' => Http::response(['output' => [['type' => 'message', 'content' => [[
        'type' => 'output_text', 'text' => json_encode($bulk ? [$row['id'] => $choice] : $choice),
    ]]]]])]);
    $this->from('/vox/sync')->post('/vox/sync/choose', $bulk ? ['entries' => [$payload]] : $payload)->assertSessionHasNoErrors();
    Http::assertSent(function ($request) use ($bulk, $row): bool {
        $input = json_decode($request['input'], true);
        $context = $bulk ? $input[$row['id']] : $input;

        return $context['default_locale'] === $row['default_locale']
            && $context['default_value'] === $row['default_value']
            && ! array_key_exists('english', $context);
    });
})->with([false, true])->with([false, true]);
