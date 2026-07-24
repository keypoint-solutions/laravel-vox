<?php

use KeypointSolutions\LaravelVox\Support\VoxSettingsRepository;
use KeypointSolutions\LaravelVox\Translation\TranslationPromptBuilder;

it('includes context line in the prompt when enabled', function () {
    config()->set('vox.translate.prompt', 'Translate :text');
    config()->set('vox.translate.terms', ['do_not_translate' => [], 'fixed' => []]);
    config()->set('vox.translate.use_context', true);

    $builder = app(TranslationPromptBuilder::class);
    $prompt = $builder->build('Save', 'en', 'fr', ['context' => 'form.submit(KEY)']);

    expect($prompt)->toContain('The user message is the only text to translate. Do not translate, copy, or include any context.')
        ->and($prompt)->toContain('Do not introduce HTML, markdown, or other markup that does not appear in the user message.')
        ->and($prompt)->toContain('BEGIN_CONTEXT')
        ->and($prompt)->toContain('form.submit(KEY)')
        ->and($prompt)->toContain('END_CONTEXT');
});

it('layers runtime guidance beneath the immutable translation instructions', function () {
    config()->set('vox.translate.prompt', 'Translate from :source to :target.');
    config()->set('vox.translate.guidance', '');
    config()->set('vox.translate.terms', ['do_not_translate' => [], 'fixed' => []]);
    config()->set('vox.translate.use_context', false);

    app(VoxSettingsRepository::class)->save([
        'translate_guidance' => 'Use formal French.',
    ]);

    $prompt = app(TranslationPromptBuilder::class)->build('Save', 'en', 'fr');

    expect($prompt)->toStartWith('Translate from en to fr.')
        ->and($prompt)->toContain('only when it does not conflict with the requirements above')
        ->and($prompt)->toContain("BEGIN_ADDITIONAL_GUIDANCE\nUse formal French.\nEND_ADDITIONAL_GUIDANCE");
});
