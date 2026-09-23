<?php

use KeypointSolutions\LaravelVox\Models\VoxTranslation;
use KeypointSolutions\LaravelVox\Models\VoxTranslationRule;
use KeypointSolutions\LaravelVox\Models\VoxTranslationValue;
use KeypointSolutions\LaravelVox\Translation\TranslationFallbackRules;
use KeypointSolutions\LaravelVox\Translation\TranslationFileRepository;
use KeypointSolutions\LaravelVox\Translation\TranslationPublisher;

beforeEach(function (): void {
    prepareVoxFixtures();
});

it('hydrates only published override rows while retaining skip counts and pending deletions', function (): void {
    for ($index = 0; $index < 25; $index++) {
        $translation = VoxTranslation::factory()->approved()->create(['group' => 'publisher_scope', 'key' => 'unchanged_'.$index]);
        $translation->values()->create(['locale' => 'en', 'value' => 'Shipped '.$index, 'file_value' => 'Shipped '.$index]);
    }

    $published = VoxTranslation::factory()->approved()->create(['group' => 'publisher_scope', 'key' => 'published']);
    $published->values()->create(['locale' => 'en', 'value' => 'Newer draft', 'file_value' => 'Shipped', 'published_override' => 'Published']);
    $published->values()->create(['locale' => 'de', 'value' => 'Unused', 'published_override' => 'Unused']);

    $pendingDelete = VoxTranslation::factory()->approved()->create([
        'group' => 'publisher_scope', 'key' => 'pending_delete', 'is_pending_delete' => true,
    ]);
    $pendingDelete->values()->create(['locale' => 'en', 'value' => 'Pending', 'published_override' => 'Still published']);

    $incomplete = VoxTranslation::factory()->approved()->create(['group' => 'publisher_scope', 'key' => 'incomplete']);
    $incomplete->values()->create(['locale' => 'en', 'value' => '🚩Missing', 'published_override' => '🚩Missing']);

    $orphan = VoxTranslation::factory()->orphan()->create(['group' => 'publisher_scope', 'key' => 'orphan']);
    $orphan->values()->create(['locale' => 'en', 'value' => 'Orphan', 'published_override' => 'Orphan']);

    app(TranslationFallbackRules::class)->save('fr', 'locale', 'default');

    $retrievedTranslations = 0;
    $retrievedValues = 0;
    VoxTranslation::retrieved(function () use (&$retrievedTranslations): void {
        $retrievedTranslations++;
    });
    VoxTranslationValue::retrieved(function () use (&$retrievedValues): void {
        $retrievedValues++;
    });

    $result = app(TranslationPublisher::class)->publishTo($this->fixtureRoot.'/lang', overridesOnly: true);
    $files = app(TranslationFileRepository::class);

    expect($result->values())->toBe(2)
        ->and($result->incompleteTranslations())->toBe(1)
        ->and($result->orphanTranslations())->toBe(1)
        ->and($files->loadGroup('en', 'publisher_scope'))->toMatchArray([
            'published' => 'Published', 'pending_delete' => 'Still published',
        ])
        ->and($retrievedTranslations)->toBe(3)
        ->and($retrievedValues)->toBe(3);
});

it('keeps default wording available for published fallback rules', function (): void {
    $translation = VoxTranslation::factory()->approved()->create(['group' => 'publisher_scope', 'key' => 'fallback']);
    $translation->values()->create(['locale' => 'en', 'value' => 'Default wording', 'file_value' => 'Default wording']);
    $rules = app(TranslationFallbackRules::class);
    $rules->save('fr', 'locale', 'default');
    VoxTranslationRule::query()->update(['published_mode' => 'default']);
    $rules->clear();

    $result = app(TranslationPublisher::class)->publishTo($this->fixtureRoot.'/lang', overridesOnly: true);

    expect(app(TranslationFileRepository::class)->loadGroup('fr', 'publisher_scope')['fallback'])->toBe('Default wording')
        ->and($result->values())->toBe(1)
        ->and($result->incompleteTranslations())->toBe(0);
});
