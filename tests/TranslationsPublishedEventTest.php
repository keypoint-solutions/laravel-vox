<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use KeypointSolutions\LaravelVox\Events\TranslationsPublished;
use KeypointSolutions\LaravelVox\Models\VoxTranslation;
use KeypointSolutions\LaravelVox\Translation\TranslationFileTransaction;
use KeypointSolutions\LaravelVox\Translation\TranslationPublisher;

beforeEach(function (): void {
    prepareVoxFixtures();
    config()->set('vox.frontend.runtime.path', $this->fixtureRoot.'/runtime');
    config()->set('vox.frontend.groups', ['messages']);
});

it('emits the configured mode and result after publishing or refreshing including no changes', function (bool $runtime, bool $refresh): void {
    config()->set('vox.frontend.runtime.enabled', $runtime);
    $events = [];
    Event::listen(TranslationsPublished::class, function (TranslationsPublished $event) use (&$events): void {
        expect(DB::connection(config('vox.database.connection'))->transactionLevel())->toBe(0);
        $events[] = $event;
    });
    $publisher = app(TranslationPublisher::class);
    $result = $refresh ? $publisher->regenerate() : $publisher->publish();

    expect($events)->toHaveCount(1)
        ->and($events[0]->frontendMode)->toBe($runtime ? 'runtime' : 'bundled')
        ->and($events[0]->publishedOnly)->toBe($refresh)
        ->and($events[0]->result)->toBe($result)
        ->and($result->frontendFileCount())->toBe($runtime ? 2 : 0);
})->with([false, true])->with([false, true]);

it('discards publication events if an enclosing database transaction rolls back', function (): void {
    Event::fake([TranslationsPublished::class]);
    $connection = DB::connection(config('vox.database.connection'));
    expect(fn () => $connection->transaction(function (): void {
        app(TranslationPublisher::class)->publish();
        Event::assertNotDispatched(TranslationsPublished::class);
        throw new RuntimeException('Rollback');
    }))->toThrow(RuntimeException::class, 'Rollback');
    Event::assertNotDispatched(TranslationsPublished::class);
});

it('discards publication events if an enclosing file transaction fails', function (): void {
    Event::fake([TranslationsPublished::class]);
    expect(fn () => app(TranslationFileTransaction::class)->run(function (): void {
        app(TranslationPublisher::class)->publish();
        Event::assertNotDispatched(TranslationsPublished::class);
        throw new RuntimeException('File rollback');
    }))->toThrow(RuntimeException::class, 'File rollback');
    Event::assertNotDispatched(TranslationsPublished::class);
    app(TranslationPublisher::class)->publish();
    Event::assertDispatchedTimes(TranslationsPublished::class, 1);
});

it('keeps committed wording intact when a publication listener fails', function (): void {
    $translation = VoxTranslation::factory()->approved()->withValues(['en' => 'Published event wording'])
        ->create(['group' => 'messages', 'key' => 'event']);
    Event::listen(TranslationsPublished::class, function (): void {
        throw new RuntimeException('Listener failed');
    });

    expect(fn () => app(TranslationPublisher::class)->publish())->toThrow(RuntimeException::class, 'Listener failed');
    expect((require $this->fixtureRoot.'/lang/en/messages.php')['event'])->toBe('Published event wording')
        ->and($translation->values()->first()->published_override)->toBe('Published event wording');
});

it('does not emit a publication event for staging or failed publication', function (): void {
    Event::fake([TranslationsPublished::class]);
    $publisher = app(TranslationPublisher::class);
    $publisher->publishTo($this->fixtureRoot.'/staged');
    Event::assertNotDispatched(TranslationsPublished::class);
    file_put_contents($this->fixtureRoot.'/lang/en/messages.php', '<?php throw new RuntimeException("Unsafe");');
    expect(fn () => $publisher->publish())->toThrow(RuntimeException::class);
    Event::assertNotDispatched(TranslationsPublished::class);
});

it('defers a selected publication until the enclosing Vox transaction commits', function (): void {
    $translation = VoxTranslation::factory()->approved()->withValues(['en' => 'Selected wording'])
        ->create(['group' => 'messages', 'key' => 'selected']);
    $events = [];
    Event::listen(TranslationsPublished::class, function (TranslationsPublished $event) use (&$events): void {
        $events[] = $event;
    });
    DB::connection(config('vox.database.connection'))->transaction(function () use ($translation, &$events): void {
        app(TranslationPublisher::class)->publish([$translation->values()->first()->id]);
        expect($events)->toBe([]);
    });
    expect($events)->toHaveCount(1)
        ->and($events[0]->result->values())->toBe(1);
});
