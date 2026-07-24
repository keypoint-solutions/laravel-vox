<?php

use App\Models\User;
use KeypointSolutions\LaravelVox\Database\Factories\VoxAuditFactory;

it('shows structured activity in the audit trail', function (): void {
    $this->actingAs(User::factory()->create(['email' => 'admin@keypoint.ro']));

    VoxAuditFactory::new()->create([
        'action' => 'translations-bulk-approved',
        'context' => ['count' => 3, 'translation_ids' => [10, 11, 12]],
        'created_at' => now(),
    ]);

    visit('/vox/audit')
        ->assertSee('Audit trail')
        ->assertSee('Bulk approved translations')
        ->assertSee('count:')
        ->assertSee('3')
        ->assertSee('translation ids:')
        ->assertSee('10, 11, 12')
        ->assertNoJavaScriptErrors();
});
