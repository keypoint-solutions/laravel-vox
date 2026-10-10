<?php

use Illuminate\Support\Carbon;
use KeypointSolutions\LaravelVox\Models\VoxAudit;
use KeypointSolutions\LaravelVox\Models\VoxTranslation;

beforeEach(function (): void {
    $this->useVoxDashboard();
    config()->set('vox.translate.locales.mode', 'configured');
    config()->set('vox.translate.locales.values', ['en', 'fr']);
    config()->set('vox.translate.base_locale', 'en');
});

it('creates concrete values covered by a dynamic pattern', function (): void {
    $this->withoutVoxCsrfMiddleware();
    config()->set('vox.retained_keys', ['enums.user_roles.*']);

    $this->from('/vox/manage')
        ->post('/vox/manage/translations', [
            'pattern' => 'enums.user_roles.*',
            'key' => 'enums.user_roles.admin',
            'values' => [
                'en' => 'Administrator',
                'fr' => '',
            ],
        ])
        ->assertRedirect('/vox/manage')
        ->assertInertiaFlash('success', 'Dynamic translation enums.user_roles.admin created.');

    $translation = VoxTranslation::query()
        ->with('values')
        ->where('group', 'enums')
        ->where('key', 'user_roles.admin')
        ->firstOrFail();

    expect($translation)
        ->status->toBe('pending')
        ->is_orphan->toBeFalse()
        ->source->toBe('dynamic')
        ->and($translation->values->pluck('value', 'locale')->all())
        ->toBe(['en' => 'Administrator'])
        ->and(VoxAudit::query()->where('action', 'dynamic-translation-created')->exists())
        ->toBeTrue();
});

it('rejects dynamic values outside the selected pattern and duplicate keys', function (): void {
    $this->withoutVoxCsrfMiddleware();
    config()->set('vox.retained_keys', ['enums.user_roles.*']);

    $this->from('/vox/manage')
        ->post('/vox/manage/translations', [
            'pattern' => 'enums.user_roles.*',
            'key' => 'enums.permissions.edit',
            'values' => ['en' => 'Edit'],
        ])
        ->assertRedirect('/vox/manage')
        ->assertSessionHasErrors('key');

    VoxTranslation::factory()->create(['group' => 'enums', 'key' => 'user_roles.admin']);

    $this->from('/vox/manage')
        ->post('/vox/manage/translations', [
            'pattern' => 'enums.user_roles.*',
            'key' => 'enums.user_roles.admin',
            'values' => ['en' => 'Administrator'],
        ])
        ->assertRedirect('/vox/manage')
        ->assertSessionHasErrors('key');
});

it('toggles approval without making translation content appear freshly updated', function (): void {
    $this->withoutVoxCsrfMiddleware();

    $contentUpdatedAt = Carbon::now()->subDays(3)->startOfSecond();
    $translation = VoxTranslation::factory()
        ->pending()
        ->withValues(['en' => 'Hello', 'fr' => 'Bonjour'])
        ->withTimestamps(Carbon::now()->subDays(4), $contentUpdatedAt)
        ->create(['group' => 'messages', 'key' => 'greeting']);

    $this->from('/vox/manage')
        ->post("/vox/manage/translations/{$translation->id}/toggle-approval")
        ->assertRedirect('/vox/manage')
        ->assertInertiaFlash('success', 'Translation approved.');

    $translation->refresh();

    expect($translation->status)->toBe('approved')
        ->and($translation->updated_at?->equalTo($contentUpdatedAt))->toBeTrue()
        ->and(VoxAudit::query()
            ->where('action', 'translation-approved')
            ->where('context->translation_id', $translation->id)
            ->exists())->toBeTrue();
});

it('bulk approves translations without changing their content timestamps', function (): void {
    $this->withoutVoxCsrfMiddleware();

    $contentUpdatedAt = Carbon::now()->subDays(3)->startOfSecond();
    $translations = VoxTranslation::factory()
        ->count(2)
        ->pending()
        ->withTimestamps(Carbon::now()->subDays(4), $contentUpdatedAt)
        ->create();

    $this->from('/vox/manage')
        ->post('/vox/manage/translations/bulk-approval', [
            'ids' => $translations->pluck('id')->all(),
            'status' => 'approved',
        ])
        ->assertRedirect('/vox/manage')
        ->assertInertiaFlash('success', 'Approved 2 translations.');

    $translations->each->refresh();

    expect($translations->pluck('status')->unique()->all())->toBe(['approved'])
        ->and($translations->every(
            fn (VoxTranslation $translation): bool => $translation->updated_at?->equalTo($contentUpdatedAt) === true
        ))->toBeTrue()
        ->and(VoxAudit::query()
            ->where('action', 'translations-bulk-approved')
            ->where('context->count', 2)
            ->exists())->toBeTrue();
});

it('returns a success flash after saving translation values', function (): void {
    $this->withoutVoxCsrfMiddleware();

    $translation = VoxTranslation::factory()
        ->withValues(['en' => 'Hello', 'fr' => 'Bonjour'])
        ->create(['group' => 'messages', 'key' => 'greeting']);

    $this->from('/vox/manage')
        ->patch("/vox/manage/translations/{$translation->id}", [
            'values' => [
                'en' => 'Hello',
                'fr' => 'Salut',
            ],
        ])
        ->assertRedirect('/vox/manage')
        ->assertInertiaFlash('success', 'Translations saved.');

    expect($translation->values()->where('locale', 'fr')->value('value'))->toBe('Salut');
    expect(VoxAudit::query()
        ->where('action', 'translation-updated')
        ->where('context->translation_id', $translation->id)
        ->exists())->toBeTrue();
});
