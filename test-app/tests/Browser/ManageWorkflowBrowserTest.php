<?php

use App\Models\User;
use KeypointSolutions\LaravelVox\Database\Factories\VoxTranslationFactory;
use KeypointSolutions\LaravelVox\Models\VoxTranslation;
use Tests\Support\BrowserTranslationDriver;

beforeEach(function (): void {
    config()->set('vox.translate.locales.mode', 'configured');
    config()->set('vox.translate.locales.values', ['en', 'fr']);
    config()->set('vox.translate.base_locale', 'en');
    config()->set('vox.retained_keys', ['browser.dynamic.*']);

    $this->actingAs(User::factory()->create(['email' => 'admin@keypoint.ro']));
});

it('creates a concrete dynamic translation from an open pattern', function (): void {
    config()->set('vox.translate.driver', BrowserTranslationDriver::class);

    VoxTranslationFactory::new()
        ->withValues(['en' => 'Ordinary browser value', 'fr' => 'Valeur ordinaire'])
        ->create(['group' => 'browser', 'key' => 'ordinary']);

    $page = visit('/vox/manage')
        ->click('[data-test="add-dynamic-translation"]')
        ->assertVisible('[data-test="dynamic-create-panel"]')
        ->select('#new_dynamic_pattern', 'browser.dynamic.*')
        ->assertSee('Prefix: browser.dynamic.');

    $panelDimensions = $page->script(
        "() => {
            const content = document.querySelector('[data-slot=\"slide-panel-content\"]');

            return {
                clientWidth: content.clientWidth,
                scrollWidth: content.scrollWidth,
            };
        }"
    );

    expect($panelDimensions['scrollWidth'])
        ->toBeLessThanOrEqual($panelDimensions['clientWidth']);

    $page->fill('[data-test="new-dynamic-key"]', 'admin')
        ->assertSeeIn('[data-test="new-dynamic-full-key"]', 'browser.dynamic.admin')
        ->fill('[data-test="new-dynamic-value-en"]', 'Administrator')
        ->pressAndWaitFor('AI fill missing')
        ->assertValue('[data-test="new-dynamic-value-fr"]', 'AI fr: Administrator')
        ->click('[data-test="store-dynamic-translation"]')
        ->waitForText('Dynamic translation browser.dynamic.admin created.')
        ->assertMissing('[data-test="dynamic-create-panel"]')
        ->pressAndWaitFor('Retained by rule')
        ->assertSee('browser.dynamic.admin')
        ->assertDontSee('browser.ordinary')
        ->assertNoJavaScriptErrors();

    $translation = VoxTranslation::query()
        ->with('values')
        ->where('group', 'browser')
        ->where('key', 'dynamic.admin')
        ->firstOrFail();

    expect($translation->is_orphan)->toBeFalse()
        ->and($translation->values->pluck('value', 'locale')->all())
        ->toBe(['en' => 'Administrator', 'fr' => 'AI fr: Administrator']);
});

it('filters and individually approves a translation with visible feedback', function (): void {
    VoxTranslationFactory::new()
        ->pending()
        ->withValues(['en' => 'Browser greeting', 'fr' => 'Salutation navigateur'])
        ->create(['group' => 'browser', 'key' => 'individual-approval']);

    VoxTranslationFactory::new()
        ->withValues(['en' => 'Other value', 'fr' => 'Autre valeur'])
        ->create(['group' => 'browser', 'key' => 'other']);

    visit('/vox/manage')
        ->type('input[placeholder="Search by key, group, or value..."]', 'individual-approval')
        ->wait(1)
        ->assertSee('browser.individual-approval')
        ->assertDontSee('browser.other')
        ->hover('[data-test="workflow-status"]')
        ->assertVisible('[role="tooltip"]')
        ->click('[data-test="approval-action"]')
        ->waitForText('Translation approved.')
        ->pressAndWaitFor('Approved')
        ->assertSee('browser.individual-approval')
        ->assertNoJavaScriptErrors();
});

it('bulk approves selected translations and can edit a translated value', function (): void {
    config()->set('vox.translate.driver', BrowserTranslationDriver::class);

    $first = VoxTranslationFactory::new()
        ->pending()
        ->withValues(['en' => 'First browser value', 'fr' => 'Première valeur'])
        ->create(['group' => 'browser', 'key' => 'bulk-first']);

    $second = VoxTranslationFactory::new()
        ->pending()
        ->withValues(['en' => 'Second browser value', 'fr' => ''])
        ->create(['group' => 'browser', 'key' => 'bulk-second']);

    $page = visit('/vox/manage?group=browser&scope=group')
        ->click('button[aria-label="Select all visible translations"]')
        ->assertSee('2 selected')
        ->pressAndWaitFor('AI translate missing')
        ->assertSee('AI translated 1 missing value across 1 translation.')
        ->assertNoJavaScriptErrors()
        ->pressAndWaitFor('Approve')
        ->assertSee('Approved 2 translations.')
        ->assertNoJavaScriptErrors();

    expect($first->fresh()->status)->toBe('approved')
        ->and($second->fresh()->status)->toBe('approved')
        ->and($second->values()->where('locale', 'fr')->value('value'))
        ->toBe('AI fr: Second browser value');

    $page->click("[data-test=\"translation-row-{$first->id}\"]")
        ->fill('[data-test="translation-value-fr"]', 'Valeur modifiée')
        ->pressAndWaitFor('Save changes')
        ->assertSeeIn('[data-test="success-toast"]', 'Translations saved.')
        ->assertMissing('[data-test="translation-edit-panel"]')
        ->assertNoJavaScriptErrors();

    expect($first->values()->where('locale', 'fr')->value('value'))->toBe('Valeur modifiée');
});

it('marks a used translation for deletion and cancels it', function (): void {
    $row = VoxTranslationFactory::new()->withValues(['en' => 'Cleanup example', 'fr' => 'Exemple'])->create(['group' => 'browser', 'key' => 'cleanup-example']);
    $page = visit('/vox/manage')
        ->click('[data-test="translation-row-'.$row->id.'"]')
        ->press('Delete key')
        ->assertSee('Language files remain unchanged until Publish.')
        ->assertMissing('#cleanup-confirmation')
        ->click('[data-test="confirm-cleanup"]')
        ->waitForText('Translation selection updated.')
        ->pressAndWaitFor('Pending deletion')
        ->assertSee('browser.cleanup-example')
        ->click('[data-test="translation-row-'.$row->id.'"]')
        ->press('Cancel deletion')
        ->fill('#cleanup-confirmation', 'CONFIRM')
        ->click('[data-test="confirm-cleanup"]')
        ->waitForText('Translation selection updated.')
        ->assertNoJavaScriptErrors();
    expect($row->fresh()->is_pending_delete)->toBeFalse();
});

it('deletes an orphan after a button confirmation without typing', function (): void {
    $row = VoxTranslationFactory::new()
        ->withValues(['en' => 'Unused value'])
        ->create(['group' => 'browser', 'key' => 'unused-orphan', 'is_orphan' => true]);

    visit('/vox/manage?status=orphan')
        ->click('[data-test="translation-row-'.$row->id.'"] [data-test="delete-translation"]')
        ->assertMissing('[data-test="translation-edit-panel"]')
        ->assertSee('Publish will permanently remove')
        ->assertMissing('#cleanup-confirmation')
        ->click('[data-test="confirm-cleanup"]')
        ->waitForText('Translation selection updated.')
        ->assertNoJavaScriptErrors();

    expect($row->fresh()->is_pending_delete)->toBeTrue();
});

it('changes page size while retaining filters and resetting the page', function (): void {
    VoxTranslationFactory::new()->count(60)->create(['group' => 'browser', 'is_orphan' => false]);

    visit('/vox/manage?group=browser&scope=group&page=2')
        ->assertSee('Page 2 of 3')
        ->select('select[aria-label="Records per page"]', '50')
        ->waitForText('Page 1 of 2')
        ->click('button[aria-label="Next page"]')
        ->waitForText('Page 2 of 2')
        ->select('select[aria-label="Records per page"]', '100')
        ->waitForText('Page 1 of 1')
        ->assertSee('60 items')
        ->assertNoJavaScriptErrors();
});

it('renders group tooltips outside the scroll container and inside the viewport', function (): void {
    VoxTranslationFactory::new()->withValues(['en' => 'Tooltip example'])->create([
        'group' => 'json', 'key' => 'Tooltip example', 'is_frontend' => true,
    ]);

    $page = visit('/vox/manage')->hover('[data-test="group-frontend-tooltip"]');
    $page->assertVisible('[role="tooltip"]');
    $bounds = $page->script("() => {
        const tooltip = document.querySelector('[role=tooltip]');
        const rect = tooltip.getBoundingClientRect();
        return { portalled: tooltip.parentElement === document.body, left: rect.left, right: rect.right, width: innerWidth };
    }");

    expect($bounds['portalled'])->toBeTrue()
        ->and($bounds['left'])->toBeGreaterThanOrEqual(0)
        ->and($bounds['right'])->toBeLessThanOrEqual($bounds['width']);
    $page->assertNoJavaScriptErrors();
});
