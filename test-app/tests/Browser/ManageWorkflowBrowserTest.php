<?php

use App\Models\User;
use KeypointSolutions\LaravelVox\Database\Factories\VoxTranslationFactory;
use KeypointSolutions\LaravelVox\Translation\Drivers\TranslationDriver;

final class BrowserTranslationDriver implements TranslationDriver
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function translate(string $text, string $sourceLocale, string $targetLocale, array $context = []): string
    {
        return "AI {$targetLocale}: {$text}";
    }
}

beforeEach(function (): void {
    config()->set('vox.translate.locales', ['en', 'fr']);
    config()->set('vox.translate.base_locale', 'en');

    $this->actingAs(User::factory()->create(['email' => 'admin@keypoint.ro']));
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
        ->assertVisible('[data-test="workflow-status"] + [role="tooltip"]')
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
