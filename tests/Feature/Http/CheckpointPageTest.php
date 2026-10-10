<?php

use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use KeypointSolutions\LaravelVox\Models\VoxTranslation;

beforeEach(function (): void {
    $this->useVoxDashboard();
    $this->withoutVoxCsrfMiddleware();
    config()->set('vox.translate.locales', ['mode' => 'configured', 'values' => ['en']]);
});

it('creates a checkpoint, records manager edits, and restores them through the UI routes', function (): void {
    $translation = VoxTranslation::factory()->withValues(['en' => 'Hello'])->create();
    $this->from('/vox/checkpoints')->post('/vox/checkpoints', ['label' => 'Before editing'])->assertSessionHasNoErrors();
    $id = DB::connection(config('vox.database.connection'))->table('vox_checkpoints')->max('id');
    $this->patch('/vox/manage/translations/'.$translation->id, ['values' => ['en' => 'Edited']])->assertSessionHasNoErrors();
    $this->get('/vox/checkpoints')->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Checkpoints', false)->has('checkpoints.data', 2)->where('checkpoints.data.0.label', 'Before editing translations')->missing('checkpoints.data.0.changes'));
    $this->post('/vox/checkpoints/'.$id.'/restore', ['confirm' => true])->assertSessionHasNoErrors();
    expect($translation->values()->firstOrFail()->value)->toBe('Hello');
});

it('requires authorization for checkpoint reads and restores', function (): void {
    config()->set('vox.system.bypass_auth_in_local', false);
    $this->get('/vox/checkpoints')->assertForbidden();
    $this->post('/vox/checkpoints', ['label' => 'Forbidden'])->assertForbidden();
    $this->post('/vox/checkpoints/1/restore', ['confirm' => true])->assertForbidden();
});
