<?php

use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use KeypointSolutions\LaravelVox\Models\VoxAudit;

beforeEach(function (): void {
    $this->withoutVite();
    app()->detectEnvironment(fn () => 'local');
    config()->set('vox.system.bypass_auth_in_local', true);
});

it('lists recent structured audit activity newest first', function (): void {
    VoxAudit::factory()->create([
        'action' => 'sync',
        'context' => ['translations' => 12],
        'created_at' => Carbon::parse('2026-07-24 09:00:00'),
    ]);
    VoxAudit::factory()->create([
        'action' => 'translations-bulk-approved',
        'context' => ['count' => 3, 'translation_ids' => [1, 2, 3]],
        'user_id' => 42,
        'created_at' => Carbon::parse('2026-07-24 10:00:00'),
    ]);

    $this->get('/vox/audit')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Audit', false)
            ->has('audits.data', 2)
            ->where('audits.data.0.action', 'translations-bulk-approved')
            ->where('audits.data.0.context.count', 3)
            ->where('audits.data.0.user_id', 42)
            ->where('audits.data.1.action', 'sync')
        );
});
