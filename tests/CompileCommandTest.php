<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use KeypointSolutions\LaravelVox\Models\VoxTranslation;

beforeEach(function (): void {
    $root = prepareVoxFixtures();
    File::deleteDirectory($root.'/lang');
    File::ensureDirectoryExists($root.'/lang/en');
    File::ensureDirectoryExists($root.'/lang/fr');
    config()->set('vox.frontend.groups.mode', 'configured');
    config()->set('vox.frontend.groups.values', ['messages']);
    config()->set('vox.translate.locales.mode', 'configured');
    config()->set('vox.translate.locales.values', ['en', 'fr']);
    config()->set('vox.frontend.runtime.path', $root.'/compiled');
    config()->set('vox.deployment.lock_path', $root.'/compile.lock');
});

it('compiles only file wording without changing source files or database state', function (): void {
    $english = "<?php return ['greeting' => 'File hello'];";
    File::put($this->fixtureRoot.'/lang/en/messages.php', $english);
    File::put($this->fixtureRoot.'/lang/fr/messages.php', "<?php return ['greeting' => 'File bonjour'];");
    File::put($this->fixtureRoot.'/lang/en.json', '{"JSON phrase":"File JSON"}');
    $translation = VoxTranslation::factory()->create(['group' => 'messages', 'key' => 'greeting']);
    $value = $translation->values()->create([
        'locale' => 'en', 'value' => 'Draft hello', 'file_value' => 'Recorded default',
        'published_override' => 'Published admin hello', 'is_approved' => true, 'is_pending_publish' => true,
    ]);
    $before = $value->fresh()->getAttributes();
    File::ensureDirectoryExists($this->fixtureRoot.'/compiled');
    File::put($this->fixtureRoot.'/compiled/stale.json', '{}');

    $this->artisan('vox:compile')->assertSuccessful();

    expect(json_decode(File::get($this->fixtureRoot.'/compiled/en.json'), true))->toBe([
        'JSON phrase' => 'File JSON', 'messages.greeting' => 'File hello',
    ])->and(json_decode(File::get($this->fixtureRoot.'/compiled/fr.json'), true))->toBe([
        'messages.greeting' => 'File bonjour',
    ])->and(File::get($this->fixtureRoot.'/lang/en/messages.php'))->toBe($english)
        ->and($value->fresh()->getAttributes())->toBe($before)
        ->and(File::exists($this->fixtureRoot.'/compiled/stale.json'))->toBeFalse();
});

it('restores previous compiled output when a later locale is invalid', function (): void {
    File::put($this->fixtureRoot.'/lang/en/messages.php', "<?php return ['greeting' => 'New file hello'];");
    File::put($this->fixtureRoot.'/lang/fr/messages.php', "<?php return getenv('VOX_TEST_VALUE');");
    File::ensureDirectoryExists($this->fixtureRoot.'/compiled');
    File::put($this->fixtureRoot.'/compiled/en.json', '{"previous":"English"}');
    File::put($this->fixtureRoot.'/compiled/fr.json', '{"previous":"French"}');

    $this->artisan('vox:compile')->assertFailed();

    expect(File::get($this->fixtureRoot.'/compiled/en.json'))->toBe('{"previous":"English"}')
        ->and(File::get($this->fixtureRoot.'/compiled/fr.json'))->toBe('{"previous":"French"}');
});

it('does not compile while another translation writer holds the lock', function (): void {
    $handle = fopen($this->fixtureRoot.'/compile.lock', 'c');
    flock($handle, LOCK_EX);
    try {
        $this->artisan('vox:compile')->assertFailed();

        expect(File::exists($this->fixtureRoot.'/compiled'))->toBeFalse();
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
});

it('compiles release language files without an initialized Vox database', function (): void {
    File::put($this->fixtureRoot.'/lang/en/messages.php', "<?php return ['greeting' => 'Release hello'];");
    File::put($this->fixtureRoot.'/lang/fr/messages.php', "<?php return ['greeting' => 'Release bonjour'];");
    $connection = config('vox.database.connection');
    DB::purge($connection);
    config()->set("database.connections.{$connection}.database", $this->fixtureRoot.'/missing/vox.sqlite');

    $this->artisan('vox:compile')->assertSuccessful();

    expect(json_decode(File::get($this->fixtureRoot.'/compiled/en.json'), true))
        ->toBe(['messages.greeting' => 'Release hello'])
        ->and(File::exists($this->fixtureRoot.'/missing/vox.sqlite'))->toBeFalse();
});
