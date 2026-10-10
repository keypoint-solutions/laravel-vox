<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use KeypointSolutions\LaravelVox\Models\VoxTranslation;
use KeypointSolutions\LaravelVox\Support\VoxMutationLock;
use KeypointSolutions\LaravelVox\Support\VoxSettingsRepository;
use KeypointSolutions\LaravelVox\Translation\Files\TranslationFileTransaction;
use KeypointSolutions\LaravelVox\Translation\Locales\LocaleProvisioner;
use KeypointSolutions\LaravelVox\Translation\Publishing\TranslationPublisher;
use KeypointSolutions\LaravelVox\Translation\Sync\TranslationDatabaseSynchronizer;
use KeypointSolutions\LaravelVox\Translation\TranslationCheckpoints;

beforeEach(function (): void {
    $this->root = base_path('tests/.tmp/checkpoints-'.Str::uuid());
    File::ensureDirectoryExists($this->root.'/lang/en');
    config()->set('vox.paths.lang', $this->root.'/lang');
    $this->checkpoints = app(TranslationCheckpoints::class);
    $this->connection = DB::connection(config('vox.database.connection'));
    $this->translation = VoxTranslation::factory()->withValues(['en' => 'Hello'])->create(['group' => 'messages', 'key' => 'greeting']);
    $this->path = $this->root.'/lang/en/messages.php';
    File::put($this->path, "<?php return ['greeting' => 'Hello'];");
});

afterEach(function (): void {
    File::deleteDirectory($this->root);
});

it('creates a cheap manual marker and remembers only changes for rollback', function (): void {
    $id = $this->checkpoints->create('Before editing');
    expect(json_decode($this->connection->table('vox_checkpoints')->find($id)->changes, true))->toBe(['rows' => [], 'files' => []]);
    app(VoxMutationLock::class)->run(function (): void {
        $this->translation->values()->firstOrFail()->saveDraft('Changed');
        app(TranslationFileTransaction::class)->replace($this->path, "<?php return ['greeting' => 'Live change'];");
    }, 'Edit wording');
    $changes = json_decode($this->connection->table('vox_checkpoints')->latest('id')->first()->changes, true);
    expect($changes['rows']['vox_translation_values'])->toHaveCount(1)
        ->and($changes['files'])->toHaveCount(1);
    $this->checkpoints->restore($id);
    expect($this->translation->values()->firstOrFail()->value)->toBe('Hello')
        ->and(File::get($this->path))->toBe("<?php return ['greeting' => 'Hello'];");
    $undo = $this->connection->table('vox_checkpoints')->max('id');
    $this->checkpoints->restore($undo);
    expect($this->translation->values()->firstOrFail()->value)->toBe('Changed');
});

it('does not checkpoint unchanged writes', function (): void {
    app(VoxMutationLock::class)->run(function (): void {
        $this->translation->values()->firstOrFail()->saveDraft('Hello');
        app(TranslationFileTransaction::class)->replace($this->path, File::get($this->path));
    });
    expect($this->connection->table('vox_checkpoints')->count())->toBe(0);
});

it('rolls back failed operations without leaving a checkpoint', function (): void {
    expect(fn () => app(VoxMutationLock::class)->run(function (): void {
        $this->translation->values()->firstOrFail()->saveDraft('Changed');
        app(TranslationFileTransaction::class)->replace($this->path, 'Changed');
        throw new RuntimeException('Failure');
    }))->toThrow(RuntimeException::class, 'Failure');
    expect($this->translation->values()->firstOrFail()->value)->toBe('Hello')
        ->and(File::get($this->path))->toContain('Hello')
        ->and($this->connection->table('vox_checkpoints')->count())->toBe(0);
});

it('refuses to overwrite file changes made outside Vox', function (): void {
    $id = $this->checkpoints->create('Before');
    app(VoxMutationLock::class)->run(fn () => app(TranslationFileTransaction::class)->replace($this->path, 'Tracked'));
    File::put($this->path, 'External');
    expect(fn () => $this->checkpoints->restore($id))->toThrow(RuntimeException::class, 'outside');
    expect(File::get($this->path))->toBe('External');
});

it('captures bulk updates and cascading deletion of translation values', function (): void {
    $id = $this->checkpoints->create('Before');
    app(VoxMutationLock::class)->run(fn () => $this->translation->values()->update(['value' => 'Bulk']));
    app(VoxMutationLock::class)->run(fn () => $this->translation->delete());
    $this->checkpoints->restore($id);
    expect(VoxTranslation::query()->findOrFail($this->translation->id)->values()->firstOrFail()->value)->toBe('Hello');
});

it('retains the newest fifty checkpoints by default and honors the saved count', function (): void {
    for ($index = 1; $index <= 52; $index++) {
        $this->checkpoints->create('Marker '.$index);
    }
    expect($this->connection->table('vox_checkpoints')->count())->toBe(50)
        ->and($this->connection->table('vox_checkpoints')->orderBy('id')->value('label'))->toBe('Marker 3');
    app(VoxSettingsRepository::class)->save(['checkpoint_limit' => 2]);
    $this->artisan('vox:checkpoint-prune')->assertSuccessful();
    expect($this->connection->table('vox_checkpoints')->orderBy('id')->pluck('label')->all())->toBe(['Marker 51', 'Marker 52']);
});

it('keeps retained checkpoints restorable after pruning older changes', function (): void {
    app(VoxSettingsRepository::class)->save(['checkpoint_limit' => 2]);
    foreach (['First', 'Second', 'Third'] as $wording) {
        app(VoxMutationLock::class)->run(fn () => $this->translation->values()->update(['value' => $wording]));
    }
    $this->checkpoints->restore($this->connection->table('vox_checkpoints')->min('id'));
    expect($this->translation->values()->firstOrFail()->value)->toBe('First')
        ->and($this->connection->table('vox_checkpoints')->count())->toBe(2);
});

it('registers daily cleanup with the Laravel scheduler', function (): void {
    $events = app(Schedule::class)->events();
    $prune = collect($events)->first(fn ($event): bool => str_contains($event->command ?? '', 'vox:checkpoint-prune'));
    expect($prune)->not->toBeNull()->and($prune->expression)->toBe('0 0 * * *');
});

it('restores an imported archive and a newly created locale', function (): void {
    config()->set('vox.translate.locales', ['mode' => 'configured', 'values' => ['en']]);
    config()->set('vox.translate.base_locale', 'en');
    config()->set('vox.parse.paths', []);
    $id = $this->checkpoints->create('Before locale');
    app(LocaleProvisioner::class)->provision('da');
    expect(File::exists($this->root.'/lang/da/messages.php'))->toBeTrue();
    $this->checkpoints->restore($id);
    expect(File::exists($this->root.'/lang/da/messages.php'))->toBeFalse()
        ->and(File::isDirectory($this->root.'/lang/da'))->toBeFalse()
        ->and(app(VoxSettingsRepository::class)->provisionedLocales())->toBe([])
        ->and($this->translation->values()->where('locale', 'da')->exists())->toBeFalse();
});

it('restores published wording and override metadata together', function (): void {
    config()->set('vox.translate.locales', ['mode' => 'configured', 'values' => ['en']]);
    config()->set('vox.translate.base_locale', 'en');
    config()->set('vox.parse.paths', []);
    app(TranslationDatabaseSynchronizer::class)->sync();
    $id = $this->checkpoints->create('Before publishing');
    app(VoxMutationLock::class)->run(fn () => $this->translation->values()->firstOrFail()->saveDraft('Published', true));
    app(TranslationPublisher::class)->publish();
    expect((require $this->path)['greeting'])->toBe('Published');
    $this->checkpoints->restore($id);
    expect((require $this->path)['greeting'])->toBe('Hello')
        ->and($this->translation->values()->firstOrFail()->published_override)->toBeNull()
        ->and($this->translation->values()->firstOrFail()->value)->toBe('Hello');
});

it('creates and restores checkpoints from the command line', function (): void {
    $this->artisan('vox:checkpoint', ['label' => 'CLI checkpoint'])->assertSuccessful();
    $id = $this->connection->table('vox_checkpoints')->max('id');
    app(VoxMutationLock::class)->run(fn () => $this->translation->values()->firstOrFail()->saveDraft('CLI draft'));
    $this->artisan('vox:checkpoint', ['--restore' => $id, '--force' => true])->assertSuccessful();
    expect($this->translation->values()->firstOrFail()->value)->toBe('Hello');
});

it('does not cascade-delete translations added outside the checkpoint history', function (): void {
    $id = $this->checkpoints->create('Before new key');
    $created = app(VoxMutationLock::class)->run(fn () => VoxTranslation::factory()->withValues(['en' => 'New'])->create());
    $created->values()->create(['locale' => 'fr', 'value' => 'External']);
    expect(fn () => $this->checkpoints->restore($id))->toThrow(RuntimeException::class, 'outside');
    expect($created->fresh()->values()->where('locale', 'fr')->value('value'))->toBe('External');
});

it('captures and restores inserts updates and deletions across checkpoint read batches', function (): void {
    $values = $this->connection->table('vox_translation_values');
    $translationId = $this->translation->id;
    for ($index = 0; $index < 450; $index++) {
        $values->insert([
            'translation_id' => $translationId,
            'locale' => 'test_'.$index,
            'value' => 'Original '.$index,
        ]);
    }
    $before = $values->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all();
    $snapshotQueries = [];
    $this->connection->beforeExecuting(function (string $query) use (&$snapshotQueries): void {
        if (str_starts_with($query, 'select * from "vox_translation_values"')) {
            $snapshotQueries[] = $query;
        }
    });

    app(VoxMutationLock::class)->run(function () use ($values, $translationId): void {
        (clone $values)->where('locale', 'test_205')->update(['value' => 'Changed']);
        (clone $values)->whereIn('locale', ['test_0', 'test_399', 'test_449'])->delete();
        $values->insert(['translation_id' => $translationId, 'locale' => 'new', 'value' => 'Added']);
    });

    expect(count($snapshotQueries))->toBeGreaterThanOrEqual(6);
    foreach ($snapshotQueries as $query) {
        expect($query)->toContain('limit 200');
    }
    $checkpoint = $this->connection->table('vox_checkpoints')->latest('id')->first();
    $changes = json_decode($checkpoint->changes, true);
    expect($changes['rows']['vox_translation_values'])->toHaveCount(5);

    $this->checkpoints->restore($checkpoint->id);

    expect($values->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all())->toBe($before);
});
