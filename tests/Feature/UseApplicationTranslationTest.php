<?php

use Illuminate\Support\Facades\File;
use KeypointSolutions\LaravelVox\Models\VoxTranslation;
use KeypointSolutions\LaravelVox\Translation\TranslationDeployment;
use KeypointSolutions\LaravelVox\Translation\TranslationPublisher;

beforeEach(function (): void {
    $this->withoutVite();
    $this->withoutVoxCsrfMiddleware();
    app()->detectEnvironment(fn () => 'local');
    config()->set('vox.system.bypass_auth_in_local', true);
    $root = prepareVoxFixtures();
    File::deleteDirectory($root.'/lang');
    File::ensureDirectoryExists($root.'/lang/en');
    File::ensureDirectoryExists($root.'/lang/fr');
    File::put($root.'/lang/en/messages.php', "<?php return ['greeting' => 'Hello'];");
    File::put($root.'/lang/fr/messages.php', "<?php return ['greeting' => 'Bonjour'];");
    config()->set('vox.parse.paths', []);
    config()->set('vox.retained_keys', []);
    config()->set('vox.frontend.manifest', $root.'/frontend.json');
    config()->set('vox.frontend.runtime.enabled', false);
    config()->set('vox.deployment.lock_path', $root.'/deployment.lock');
    app(TranslationDeployment::class)->deploy();
    $this->translation = VoxTranslation::query()->where('key', 'greeting')->firstOrFail();
});

it('restores application wording for one locale while preserving a draft and other overrides', function (): void {
    $english = $this->translation->values()->where('locale', 'en')->firstOrFail();
    $french = $this->translation->values()->where('locale', 'fr')->firstOrFail();
    $english->saveDraft('Manager hello', true);
    $french->saveDraft('Manager bonjour', true);
    app(TranslationPublisher::class)->publish();
    $english->refresh()->saveDraft('Future hello');

    $this->from('/vox/manage')->post('/vox/manage/translations/'.$this->translation->id.'/use-application', ['locale' => 'en'])
        ->assertRedirect('/vox/manage')->assertSessionHasNoErrors();

    expect((require $this->fixtureRoot.'/lang/en/messages.php')['greeting'])->toBe('Hello')
        ->and($english->fresh()->published_override)->toBeNull()
        ->and($english->fresh()->value)->toBe('Future hello')
        ->and($english->fresh()->is_pending_publish)->toBeTrue()
        ->and($english->fresh()->is_approved)->toBeFalse()
        ->and($french->fresh()->published_override)->toBe('Manager bonjour')
        ->and((require $this->fixtureRoot.'/lang/fr/messages.php')['greeting'])->toBe('Manager bonjour');
});

it('requires authorization to restore application wording', function (): void {
    $english = $this->translation->values()->where('locale', 'en')->firstOrFail();
    $english->saveDraft('Manager hello', true);
    app(TranslationPublisher::class)->publish();
    config()->set('vox.system.bypass_auth_in_local', false);

    $this->postJson('/vox/manage/translations/'.$this->translation->id.'/use-application', ['locale' => 'en'])->assertForbidden();

    expect($english->fresh()->published_override)->toBe('Manager hello')
        ->and((require $this->fixtureRoot.'/lang/en/messages.php')['greeting'])->toBe('Manager hello');
});

it('retains newly created dynamic drafts through deployment until approved and published', function (): void {
    config()->set('vox.retained_keys', ['custom.*']);
    $this->from('/vox/manage')->post('/vox/manage/translations', [
        'pattern' => 'custom.*', 'key' => 'custom.notice',
        'values' => ['en' => 'New notice', 'fr' => ''],
    ])->assertRedirect('/vox/manage')->assertSessionHasNoErrors();
    $translation = VoxTranslation::query()->where('group', 'custom')->where('key', 'notice')->firstOrFail();
    $value = $translation->values()->where('locale', 'en')->firstOrFail();
    expect($value->is_pending_publish)->toBeTrue()->and($value->is_approved)->toBeFalse();

    app(TranslationDeployment::class)->deploy();
    expect($value->fresh()->value)->toBe('New notice')
        ->and($value->fresh()->is_pending_publish)->toBeTrue()
        ->and($translation->fresh()->is_orphan)->toBeFalse()
        ->and(File::exists($this->fixtureRoot.'/lang/en/custom.php'))->toBeFalse();

    $this->from('/vox/manage')->post('/vox/manage/translations/'.$translation->id.'/toggle-approval')
        ->assertRedirect('/vox/manage');
    app(TranslationPublisher::class)->publish();
    expect((require $this->fixtureRoot.'/lang/en/custom.php')['notice'])->toBe('New notice')
        ->and($value->fresh()->published_override)->toBe('New notice');
});

it('removes both representations and deletes an empty file when no application value exists', function (): void {
    $translation = VoxTranslation::factory()->create(['group' => 'custom', 'key' => 'promo.line']);
    $translation->values()->create(['locale' => 'en', 'value' => 'Override', 'published_override' => 'Override', 'is_approved' => true]);
    $path = $this->fixtureRoot.'/lang/en/custom.php';
    File::put($path, "<?php return ['promo' => ['line' => 'Override'], 'promo.line' => 'Override'];");
    $this->post('/vox/manage/translations/'.$translation->id.'/use-application', ['locale' => 'en'])->assertSessionHasNoErrors();
    expect(File::exists($path))->toBeFalse();
});

it('restores application wording using the configured group format', function (string $format): void {
    config()->set('vox.parse.output', $format);
    config()->set('vox.parse.preserve_existing_format', false);
    $translation = VoxTranslation::factory()->create(['group' => 'custom', 'key' => 'promo.line']);
    $translation->values()->create(['locale' => 'en', 'value' => 'Override', 'file_value' => 'Original', 'published_override' => 'Override', 'is_approved' => true]);
    $path = $this->fixtureRoot.'/lang/en/custom.php';
    File::put($path, "<?php return ['promo' => ['line' => 'Override'], 'promo.line' => 'Override'];");
    $this->post('/vox/manage/translations/'.$translation->id.'/use-application', ['locale' => 'en'])->assertSessionHasNoErrors();
    expect(require $path)->toBe($format === 'flat' ? ['promo.line' => 'Original'] : ['promo' => ['line' => 'Original']]);
})->with(['flat', 'nested']);
