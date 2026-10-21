<?php

use Illuminate\Support\Carbon;
use KeypointSolutions\LaravelVox\Tests\Support\SlowTranslationDriver;
use KeypointSolutions\LaravelVox\Translation\Drivers\BatchTranslationDriver;
use KeypointSolutions\LaravelVox\Translation\Drivers\TranslationBatcher;

it('splits items into batches by count and by source length, keeping keys', function (): void {
    $batcher = new TranslationBatcher;
    $items = [];

    foreach (range(1, TranslationBatcher::MAX_ITEMS + 2) as $index) {
        $items["key{$index}"] = ['text' => "Text {$index}", 'target' => 'fr'];
    }

    $batches = $batcher->chunk($items);

    expect($batches)->toHaveCount(2)
        ->and(array_key_first($batches[1]))->toBe('key'.(TranslationBatcher::MAX_ITEMS + 1));

    $long = str_repeat('a', intdiv(TranslationBatcher::MAX_CHARACTERS, 2) + 1);

    expect($batcher->chunk([
        'a' => ['text' => $long, 'target' => 'fr'],
        'b' => ['text' => $long, 'target' => 'fr'],
        'c' => ['text' => 'short', 'target' => 'fr'],
    ]))->toBe([
        ['a' => ['text' => $long, 'target' => 'fr']],
        ['b' => ['text' => $long, 'target' => 'fr'], 'c' => ['text' => 'short', 'target' => 'fr']],
    ]);
});

it('translates one item at a time with drivers that cannot batch, stopping at the deadline', function (): void {
    Carbon::setTestNow('2026-01-01 12:00:00');

    $results = (new TranslationBatcher)->translate(new SlowTranslationDriver, [
        'a' => ['text' => 'One', 'target' => 'fr'],
        'b' => ['text' => 'Two', 'target' => 'fr'],
        'c' => ['text' => 'Three', 'target' => 'fr'],
    ], 'en', now()->addSeconds(20));

    expect($results)->toBe(['a' => 'fr: One', 'b' => 'fr: Two']);
});

it('reports a failed batch request against its first item', function (): void {
    $driver = new class implements BatchTranslationDriver
    {
        public function translate(string $text, string $sourceLocale, string $targetLocale, array $context = []): string
        {
            return $text;
        }

        public function translateMany(array $items, string $sourceLocale): array
        {
            throw new RuntimeException('Quota exceeded.');
        }
    };

    $results = (new TranslationBatcher)->translate($driver, [
        'a' => ['text' => 'One', 'target' => 'fr'],
        'b' => ['text' => 'Two', 'target' => 'fr'],
    ], 'en');

    expect(array_keys($results))->toBe(['a'])
        ->and($results['a']->getMessage())->toBe('Quota exceeded.');
});
