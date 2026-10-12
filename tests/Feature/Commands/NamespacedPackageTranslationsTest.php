<?php

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use KeypointSolutions\LaravelVox\Models\VoxTranslation;
use KeypointSolutions\LaravelVox\Translation\Files\TranslationFileRepository;
use KeypointSolutions\LaravelVox\Translation\Files\TranslationFileUpdater;
use KeypointSolutions\LaravelVox\Translation\Publishing\TranslationPublisher;

function prepareNamespacedPackageTranslations(): string
{
    $root = prepareVoxFixtures();
    config()->set('vox.translate.locales.values', ['en', 'fr', 'nl']);
    config()->set('vox.parse.paths', [$root.'/resources/views']);
    File::put($root.'/resources/views/welcome.blade.php', <<<'BLADE'
{{ __('two-factor::components.enable.header') }}
{{ __('two-factor::components.enable.description') }}
{{ __('two-factor::components.logout.button') }}
BLADE);
    foreach (['en', 'fr', 'de'] as $locale) {
        File::ensureDirectoryExists($root.'/package/lang/'.$locale);
    }
    File::put($root.'/package/lang/en/components.php', "<?php return ['enable' => ['header' => 'Enable two-factor authentication', 'description' => 'Protect your account'], 'logout' => ['button' => 'Log out'], 'unreferenced' => 'Package only'];");
    File::put($root.'/package/lang/fr/components.php', "<?php return ['enable' => ['header' => 'Activer la double authentification']];");
    File::put($root.'/package/lang/de/components.php', "<?php return ['enable' => ['description' => 'Konto schützen'], 'logout' => ['button' => 'Abmelden']];");
    app('translator')->addNamespace('two-factor', $root.'/package/lang');
    app('translation.loader')->addPath($root.'/lang');
    app('translator')->setFallback('en');

    return $root;
}

it('parses registered package defaults and locale fallbacks without inventing overrides', function (): void {
    $root = prepareNamespacedPackageTranslations();
    $files = app(TranslationFileRepository::class);
    $packageContents = File::get($root.'/package/lang/en/components.php');
    $expected = [];
    foreach (['en', 'fr', 'nl'] as $locale) {
        foreach (['enable.header', 'enable.description', 'logout.button'] as $key) {
            $expected[$locale][$key] = app('translator')->get('two-factor::components.'.$key, [], $locale);
        }
    }

    $this->artisan('vox:parse', ['--no-interaction' => true])->assertExitCode(0);

    foreach ($expected as $locale => $values) {
        expect(Arr::dot($files->loadGroup($locale, 'two-factor::components')))->toEqual($values);
    }
    expect($files->loadGroup('en', 'two-factor::components'))->not->toHaveKey('unreferenced')
        ->and(File::get($root.'/package/lang/en/components.php'))->toBe($packageContents);

    app('translator')->setLoaded([]);
    expect(app('translator')->get('two-factor::components.enable.header', [], 'fr'))->toBe($expected['fr']['enable.header'])
        ->and(app('translator')->get('two-factor::components.logout.button', [], 'nl'))->toBe('Log out');
});

it('preserves partial application overrides and uses them only after locale package defaults', function (): void {
    prepareNamespacedPackageTranslations();
    $files = app(TranslationFileRepository::class);
    $files->saveGroup('en', 'two-factor::components', ['enable' => ['header' => 'Custom English header', 'description' => 'Custom protection']]);
    $files->saveGroup('fr', 'two-factor::components', ['logout' => ['button' => 'Sortie personnalisée']]);

    $this->artisan('vox:parse', ['--no-interaction' => true])->assertExitCode(0);

    expect(Arr::dot($files->loadGroup('en', 'two-factor::components')))->toEqual([
        'enable.header' => 'Custom English header',
        'enable.description' => 'Custom protection',
        'logout.button' => 'Log out',
    ])->and(Arr::dot($files->loadGroup('fr', 'two-factor::components')))->toEqual([
        'enable.header' => 'Activer la double authentification',
        'enable.description' => 'Custom protection',
        'logout.button' => 'Sortie personnalisée',
    ])->and(Arr::get($files->loadGroup('nl', 'two-factor::components'), 'enable.header'))->toBe('Custom English header');
});

it('uses the Laravel fallback locale independently of the Vox base locale', function (): void {
    prepareNamespacedPackageTranslations();
    app('translator')->setFallback('de');
    $files = app(TranslationFileRepository::class);
    $files->saveGroup('de', 'two-factor::components', ['enable' => ['header' => 'Fallback override']]);

    $this->artisan('vox:parse', ['--no-interaction' => true])->assertExitCode(0);

    expect(Arr::dot($files->loadGroup('nl', 'two-factor::components')))->toEqual([
        'enable.header' => 'Fallback override',
        'enable.description' => 'Konto schützen',
        'logout.button' => 'Abmelden',
    ])->and(Arr::get($files->loadGroup('fr', 'two-factor::components'), 'enable.header'))->toBe('Activer la double authentification')
        ->and(Arr::get($files->loadGroup('en', 'two-factor::components'), 'logout.button'))->toBe('Log out');
});

it('keeps repeated parse sync and publish scoped to application translation files', function (): void {
    $root = prepareNamespacedPackageTranslations();
    $files = app(TranslationFileRepository::class);
    $this->artisan('vox:sync', ['--parse' => true, '--no-interaction' => true])->assertExitCode(0);
    $contents = [];
    foreach (['en', 'fr', 'nl'] as $locale) {
        $contents[$locale] = File::get($files->groupPath($locale, 'two-factor::components'));
    }
    $this->artisan('vox:sync', ['--parse' => true, '--no-interaction' => true])->assertExitCode(0);
    app(TranslationPublisher::class)->publish();
    foreach ($contents as $locale => $content) {
        expect(File::get($files->groupPath($locale, 'two-factor::components')))->toBe($content);
    }
    expect(VoxTranslation::query()->where('group', 'two-factor::components')->count())->toBe(3)
        ->and(VoxTranslation::query()->where('group', 'two-factor::components')->where('key', 'unreferenced')->exists())->toBeFalse()
        ->and(File::exists($root.'/lang/vendor/two-factor/de/components.php'))->toBeFalse();

    $scan = collect(['enable.header', 'enable.description', 'logout.button'])->mapWithKeys(fn (string $key): array => [
        'two-factor::components.'.$key => ['group' => 'two-factor::components', 'key' => $key, 'occurrences' => []],
    ])->all();
    $result = app(TranslationFileUpdater::class)->updateFromScan($scan, ['en', 'fr', 'nl'], 'en');
    expect($result->added())->toBe(0)->and($result->removed())->toBe(0);
});

it('retains existing blank overrides and marks truly absent package keys as missing', function (): void {
    $root = prepareNamespacedPackageTranslations();
    File::append($root.'/resources/views/welcome.blade.php', "\n{{ __('two-factor::components.enable.unknown') }}\n{{ __('messages.new_label') }}");
    $files = app(TranslationFileRepository::class);
    $files->saveGroup('en', 'two-factor::components', ['logout' => ['button' => '']]);
    $files->saveGroup('fr', 'two-factor::components', ['logout' => ['button' => '🚩Custom draft']]);

    $this->artisan('vox:parse', ['--no-interaction' => true])->assertExitCode(0);

    expect(Arr::get($files->loadGroup('en', 'two-factor::components'), 'logout.button'))->toBe('')
        ->and(Arr::get($files->loadGroup('fr', 'two-factor::components'), 'logout.button'))->toBe('🚩Custom draft')
        ->and(Arr::get($files->loadGroup('en', 'two-factor::components'), 'enable.unknown'))->toBe('unknown')
        ->and(Arr::get($files->loadGroup('fr', 'two-factor::components'), 'enable.unknown'))->toBe('🚩unknown')
        ->and(Arr::get($files->loadGroup('en', 'messages'), 'new_label'))->toBe('new_label')
        ->and(Arr::get($files->loadGroup('fr', 'messages'), 'new_label'))->toBe('🚩new_label');
});
