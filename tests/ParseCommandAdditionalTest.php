<?php

use Illuminate\Support\Facades\File;
use KeypointSolutions\LaravelVox\Translation\TranslationScanner;
use KeypointSolutions\LaravelVox\Translation\TranslationFileWriter;

it('removes orphan protected keys in non-base locales when keep_orphan_other_locales_keys is false', function () {
    $targetRoot = prepareVoxFixtures();

    config()->set('vox.parse.keep_orphan_other_locales_keys', false);
    config()->set('vox.parse.protected_keys', ['messages.']);

    $frMessagesPath = $targetRoot.'/lang/fr/messages.php';
    $frMessages = require $frMessagesPath;
    $frMessages['test'] = 'Test';
    File::put($frMessagesPath, "<?php\n\nreturn ".var_export($frMessages, true).";\n");

    $this->artisan('vox:parse', ['--no-interaction' => true])->assertExitCode(0);

    $updated = require $frMessagesPath;
    expect($updated)->not->toHaveKey('test');

    File::deleteDirectory($targetRoot);
});

it('keeps obsolete comment lines between parse runs', function () {
    $targetRoot = prepareVoxFixtures();

    config()->set('vox.parse.obsolete', 'comment');

    $writer = new TranslationFileWriter();
    $authPath = $targetRoot.'/lang/en/auth.php';

    $this->artisan('vox:parse', ['--no-interaction' => true])->assertExitCode(0);
    $contents = File::get($authPath);

    expect($contents)->toContain($writer->obsoleteCommentPrefix())
        ->and($contents)->toContain("'obsolete' =>");

    $this->artisan('vox:parse', ['--no-interaction' => true])->assertExitCode(0);
    $contents = File::get($authPath);

    expect($contents)->toContain($writer->obsoleteCommentPrefix())
        ->and($contents)->toContain("'obsolete' =>");

    File::deleteDirectory($targetRoot);
});

it('uses the leaf segment for default values on nested keys', function () {
    $targetRoot = prepareVoxFixtures();

    $jsPath = $targetRoot.'/resources/js/app.js';
    File::append($jsPath, "\ntrans('frontend.dynamicLabels.labels.Dynamic Label 1');\ntrans('frontend.OK.');\n");

    $this->artisan('vox:parse', ['--no-interaction' => true])->assertExitCode(0);

    $enFrontend = require $targetRoot.'/lang/en/frontend.php';

    expect($enFrontend['dynamicLabels.labels.Dynamic Label 1'])->toBe('Dynamic Label 1')
        ->and($enFrontend['OK.'])->toBe('OK.');

    File::deleteDirectory($targetRoot);
});

it('parses vue translations and json strings from Welcome.vue', function () {
    $targetRoot = prepareVoxFixtures();

    config()->set('vox.parse.add_context_comments', true);
    config()->set('vox.parse.add_occurrence_comments', true);

    $this->artisan('vox:parse')->assertExitCode(0);

    $enFrontend = require $targetRoot.'/lang/en/frontend.php';
    $enJson = json_decode(File::get($targetRoot.'/lang/en.json'), true);
    $frontendContents = File::get($targetRoot.'/lang/en/frontend.php');
    $multilineKey = "Multiline line one\nline two\nline three";
    $multilineJsonKey = "This is\nmultiline JSON";

    expect($enFrontend)->toHaveKey('Regular translation')
        ->and($enFrontend['Works.'])->toBe('Works.')
        ->and($enFrontend['grouped.Works.'])->toBe('Works.')
        ->and($enFrontend['Today is :date, :time.'])->toBe('Today is :date, :time.')
        ->and($enFrontend['dynamicLabels.labels.Dynamic Label 1'])->toBe('Dynamic Label 1')
        ->and($enFrontend[$multilineKey])->toBe($multilineKey)
        ->and($enJson)->toHaveKey('This ends up in JSON')
        ->and($enJson['This ends up in JSON too.'])->toBe('This ends up in JSON too.')
        ->and($enJson[$multilineJsonKey])->toBe($multilineJsonKey)
        ->and($frontendContents)->toContain('$t(KEY)')
        ->and($frontendContents)->toContain('Welcome.vue:');

    File::deleteDirectory($targetRoot);
});

it('keeps lowercase label for nested field label keys', function () {
    $targetRoot = prepareVoxFixtures();

    File::append($targetRoot.'/resources/js/app.js', "\n\$t('form_section.fields.reference2.label');\n");

    $this->artisan('vox:parse', ['--no-interaction' => true])->assertExitCode(0);

    $enForm = require $targetRoot.'/lang/en/form_section.php';

    expect($enForm['fields.reference2.label'])->toBe('label');

    File::deleteDirectory($targetRoot);
});

it('detects dynamic vue keys from concatenation and template strings', function () {
    $targetRoot = prepareVoxFixtures();

    $scanner = new TranslationScanner(
        base_path(),
        [$targetRoot.'/resources'],
        [],
        ['vue'],
        1
    );

    $scanner->scan();
    $prefixes = array_map(fn (array $entry) => $entry['prefix'], $scanner->dynamicKeys());

    expect($prefixes)->toContain('frontend.dynamicLabels.values.')
        ->and($prefixes)->toContain('frontend.dynamicLabels2.values.');

    File::deleteDirectory($targetRoot);
});
