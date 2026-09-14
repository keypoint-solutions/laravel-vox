<?php

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\File;
use KeypointSolutions\LaravelVox\Models\VoxTranslation;
use KeypointSolutions\LaravelVox\Translation\TranslationDeployment;
use KeypointSolutions\LaravelVox\Translation\TranslationPublisher;

beforeEach(function (): void {
    $this->withoutVite();
    $this->withoutMiddleware(PreventRequestForgery::class);
    app()->detectEnvironment(fn () => 'local');
    config()->set('vox.system.bypass_auth_in_local', true);
    $root = prepareVoxFixtures();
    File::deleteDirectory($root.'/lang');
    File::ensureDirectoryExists($root.'/lang/en');
    File::ensureDirectoryExists($root.'/lang/fr');
    File::put($root.'/lang/en/messages.php', "<?php return ['greeting' => 'Hello'];");
    File::put($root.'/lang/fr/messages.php', "<?php return ['greeting' => 'Bonjour'];");
    config()->set('vox.parse.paths', []);
    config()->set('vox.dynamic_keys.patterns', []);
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
    config()->set('vox.dynamic_keys.patterns', ['custom.*']);
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
