<?php

use Illuminate\Support\Facades\File;

it('skips placeholder keys that are not translated in the base locale', function () {
    $targetRoot = prepareVoxFixtures();

    config()->set('vox.translate.driver', 'null');

    $this->artisan('vox:translate')->assertExitCode(0);

    $frMessages = require $targetRoot.'/lang/fr/messages.php';

    expect($frMessages['lblButton'])->toStartWith('🚩');

    File::deleteDirectory($targetRoot);
});

it('translates only the specified lang file across locales', function () {
    $targetRoot = prepareVoxFixtures();

    config()->set('vox.translate.driver', 'null');

    $this->artisan('vox:translate', ['--path' => 'en/messages.php', '--no-interaction' => true])->assertExitCode(0);

    $frMessages = require $targetRoot.'/lang/fr/messages.php';
    $frAuth = require $targetRoot.'/lang/fr/auth.php';

    expect($frMessages['count'])->toBe('Count :count')
        ->and($frAuth)->not->toHaveKey('obsolete');

    File::deleteDirectory($targetRoot);
});

it('flattens translated output when preserve_existing_format is disabled', function () {
    $targetRoot = prepareVoxFixtures();

    config()->set('vox.translate.driver', 'null');
    config()->set('vox.parse.output', 'flat');
    config()->set('vox.parse.preserve_existing_format', false);

    File::put($targetRoot.'/lang/en/nested.php', "<?php\n\nreturn [\n    'action' => [\n        'save' => 'Save',\n    ],\n];\n");
    File::put($targetRoot.'/lang/fr/nested.php', "<?php\n\nreturn [\n    'action' => [\n        'save' => '🚩Save',\n    ],\n];\n");

    $this->artisan('vox:translate', ['--path' => 'en/nested.php', '--no-interaction' => true])->assertExitCode(0);

    $frNested = require $targetRoot.'/lang/fr/nested.php';

    expect($frNested)->toHaveKey('action.save')
        ->and($frNested['action.save'])->toBe('Save');

    File::deleteDirectory($targetRoot);
});

it('skips non-string values when translating group files', function () {
    $targetRoot = prepareVoxFixtures();

    config()->set('vox.translate.driver', 'null');

    File::put($targetRoot.'/lang/en/validation.php', "<?php\n\nreturn [\n    'custom' => [],\n    'attributes' => [\n        'email' => 'Email address',\n    ],\n];\n");
    File::put($targetRoot.'/lang/fr/validation.php', "<?php\n\nreturn [\n    'custom' => [],\n    'attributes' => [\n        'email' => '🚩Email address',\n    ],\n];\n");

    $this->artisan('vox:translate', ['--path' => 'en/validation.php', '--no-interaction' => true])->assertExitCode(0);

    $frValidation = require $targetRoot.'/lang/fr/validation.php';

    expect($frValidation)->toHaveKey('custom')
        ->and($frValidation['custom'])->toBe([])
        ->and($frValidation['attributes']['email'])->toBe('Email address');

    File::deleteDirectory($targetRoot);
});

it('preserves inline comments when translating group files', function () {
    $targetRoot = prepareVoxFixtures();

    config()->set('vox.translate.driver', 'null');

    File::put($targetRoot.'/lang/en/sample.php', "<?php\n\nreturn [\n    // Context sample\n    // File:Some.php:10\n    'label' => 'Label',\n];\n");
    File::put($targetRoot.'/lang/fr/sample.php', "<?php\n\nreturn [\n    // Context sample\n    // File:Some.php:10\n    'label' => '🚩Label',\n];\n");

    $this->artisan('vox:translate', ['--path' => 'en/sample.php', '--no-interaction' => true])->assertExitCode(0);

    $contents = File::get($targetRoot.'/lang/fr/sample.php');

    expect($contents)->toContain('// Context sample')
        ->and($contents)->toContain('// File:Some.php:10');

    File::deleteDirectory($targetRoot);
});

it('preserves inline comments for multiline keys when translating group files', function () {
    $targetRoot = prepareVoxFixtures();

    config()->set('vox.translate.driver', 'null');

    $enContents = <<<'PHP'
<?php

return [
    // Multiline context
    // File:Example.php:10
    'Line one
        Line two' => 'Line one
        Line two',
];
PHP;
    $frContents = <<<'PHP'
<?php

return [
    // Multiline context
    // File:Example.php:10
    'Line one
        Line two' => '🚩Line one
        Line two',
];
PHP;

    File::put($targetRoot.'/lang/en/sample.php', $enContents);
    File::put($targetRoot.'/lang/fr/sample.php', $frContents);

    $this->artisan('vox:translate', ['--path' => 'en/sample.php', '--no-interaction' => true])->assertExitCode(0);

    $contents = File::get($targetRoot.'/lang/fr/sample.php');

    expect($contents)->toContain('// Multiline context')
        ->and($contents)->toContain('// File:Example.php:10');

    File::deleteDirectory($targetRoot);
});

it('translates only the requested key when key option is provided', function () {
    $targetRoot = prepareVoxFixtures();

    config()->set('vox.translate.driver', 'null');

    File::put($targetRoot.'/lang/en/sample.php', "<?php\n\nreturn [\n    'count' => 'Count :count',\n    'title' => 'Title',\n];\n");
    File::put($targetRoot.'/lang/fr/sample.php', "<?php\n\nreturn [\n    'count' => '🚩Count :count',\n    'title' => '🚩Title',\n];\n");

    $this->artisan('vox:translate', ['--path' => 'en/sample.php', '--key' => 'count', '--no-interaction' => true])->assertExitCode(0);

    $frSample = require $targetRoot.'/lang/fr/sample.php';

    expect($frSample['count'])->toBe('Count :count')
        ->and($frSample['title'])->toBe('🚩Title');

    File::deleteDirectory($targetRoot);
});

it('forces retranslation for a requested key', function () {
    $targetRoot = prepareVoxFixtures();

    config()->set('vox.translate.driver', 'null');

    File::put($targetRoot.'/lang/en/sample.php', "<?php\n\nreturn [\n    'count' => 'Count :count',\n    'title' => 'Title',\n];\n");
    File::put($targetRoot.'/lang/fr/sample.php', "<?php\n\nreturn [\n    'count' => 'Nombre :count',\n    'title' => '🚩Title',\n];\n");

    $this->artisan('vox:translate', ['--path' => 'en/sample.php', '--key' => 'count', '--force' => true, '--no-interaction' => true])->assertExitCode(0);

    $frSample = require $targetRoot.'/lang/fr/sample.php';

    expect($frSample['count'])->toBe('Count :count')
        ->and($frSample['title'])->toBe('🚩Title');

    File::deleteDirectory($targetRoot);
});
