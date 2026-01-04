<?php

use Illuminate\Support\Facades\File;
use KeypointSolutions\LaravelVox\Models\VoxTranslation;
use KeypointSolutions\LaravelVox\Models\VoxTranslationOccurrence;
use KeypointSolutions\LaravelVox\Models\VoxTranslationValue;

it('syncs translation files into the vox database', function () {
    $targetRoot = prepareVoxFixtures();

    $this->artisan('vox:parse', ['--no-interaction' => true])->assertExitCode(0);
    $this->artisan('vox:sync')->assertExitCode(0);

    $translation = VoxTranslation::query()
        ->where('group', 'messages')
        ->where('key', 'hello')
        ->first();

    expect($translation)->not->toBeNull()
        ->and($translation->is_frontend)->toBeTrue();

    $value = VoxTranslationValue::query()
        ->where('translation_id', $translation->id)
        ->where('locale', 'fr')
        ->first();

    expect($value)->not->toBeNull()
        ->and($value->value)->toStartWith('🚩');

    $occurrence = VoxTranslationOccurrence::query()
        ->where('translation_id', $translation->id)
        ->first();

    expect($occurrence)->not->toBeNull();

    $vendorTranslation = VoxTranslation::query()
        ->where('group', 'vendorpkg::messages')
        ->where('key', 'title')
        ->first();

    expect($vendorTranslation)->not->toBeNull();

    File::deleteDirectory($targetRoot);
});
