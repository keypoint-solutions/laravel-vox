<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use KeypointSolutions\LaravelVox\Commands\TranslateMissingTranslationsCommand;
use KeypointSolutions\LaravelVox\Tests\Support\EchoTranslationDriver;

it('keeps translation spinner messages within the terminal width', function (int $columns, string $label): void {
    $previousColumns = getenv('COLUMNS');
    putenv("COLUMNS={$columns}");

    try {
        $command = new TranslateMissingTranslationsCommand;
        $method = new ReflectionMethod($command, 'formatSpinnerMessage');
        $message = $method->invoke($command, $label, 'fr');

        expect(mb_strwidth(' ⠋ '.$message, 'UTF-8'))->toBeLessThan($columns)
            ->and($message)->not->toContain("\n");

        if ($label === 'messages.save') {
            expect($message)->toBe('Translating messages.save to fr');
        }
    } finally {
        putenv($previousColumns === false ? 'COLUMNS' : "COLUMNS={$previousColumns}");
    }
})->with([
    'short label' => [80, 'messages.save'],
    'long label' => [80, str_repeat('long-key-', 15)],
    'narrow terminal' => [30, str_repeat('long-key-', 15)],
    'wide Unicode label' => [80, str_repeat('翻訳', 40)],
    'very narrow terminal' => [5, 'long-key'],
]);

it('skips placeholder keys that are not translated in the base locale', function () {
    $targetRoot = prepareVoxFixtures();

    config()->set('vox.translate.driver', EchoTranslationDriver::class);

    $this->artisan('vox:translate')->assertExitCode(0);

    $frMessages = require $targetRoot.'/lang/fr/messages.php';

    expect($frMessages['lblButton'])->toStartWith('🚩');
});

it('translates only the specified lang file across locales', function () {
    $targetRoot = prepareVoxFixtures();

    config()->set('vox.translate.driver', EchoTranslationDriver::class);

    $this->artisan('vox:translate', ['--path' => 'en/messages.php', '--no-interaction' => true])->assertExitCode(0);

    $frMessages = require $targetRoot.'/lang/fr/messages.php';
    $frAuth = require $targetRoot.'/lang/fr/auth.php';

    expect($frMessages['count'])->toBe('Count :count')
        ->and($frAuth)->not->toHaveKey('obsolete');
});

it('flattens translated output when preserve_existing_format is disabled', function () {
    $targetRoot = prepareVoxFixtures();

    config()->set('vox.translate.driver', EchoTranslationDriver::class);
    config()->set('vox.parse.output', 'flat');
    config()->set('vox.parse.preserve_existing_format', false);

    File::put($targetRoot.'/lang/en/nested.php', "<?php\n\nreturn [\n    'action' => [\n        'save' => 'Save',\n    ],\n];\n");
    File::put($targetRoot.'/lang/fr/nested.php', "<?php\n\nreturn [\n    'action' => [\n        'save' => '🚩Save',\n    ],\n];\n");

    $this->artisan('vox:translate', ['--path' => 'en/nested.php', '--no-interaction' => true])->assertExitCode(0);

    $frNested = require $targetRoot.'/lang/fr/nested.php';

    expect($frNested)->toHaveKey('action.save')
        ->and($frNested['action.save'])->toBe('Save');
});

it('skips non-string values when translating group files', function () {
    $targetRoot = prepareVoxFixtures();

    config()->set('vox.translate.driver', EchoTranslationDriver::class);

    File::put($targetRoot.'/lang/en/validation.php', "<?php\n\nreturn [\n    'custom' => [],\n    'attributes' => [\n        'email' => 'Email address',\n    ],\n];\n");
    File::put($targetRoot.'/lang/fr/validation.php', "<?php\n\nreturn [\n    'custom' => [],\n    'attributes' => [\n        'email' => '🚩Email address',\n    ],\n];\n");

    $this->artisan('vox:translate', ['--path' => 'en/validation.php', '--no-interaction' => true])->assertExitCode(0);

    $frValidation = require $targetRoot.'/lang/fr/validation.php';

    expect($frValidation)->toHaveKey('custom')
        ->and($frValidation['custom'])->toBe([])
        ->and($frValidation['attributes']['email'])->toBe('Email address');
});

it('preserves inline comments when translating group files', function () {
    $targetRoot = prepareVoxFixtures();

    config()->set('vox.translate.driver', EchoTranslationDriver::class);

    File::put($targetRoot.'/lang/en/sample.php', "<?php\n\nreturn [\n    // Context sample\n    // File:Some.php:10\n    'label' => 'Label',\n];\n");
    File::put($targetRoot.'/lang/fr/sample.php', "<?php\n\nreturn [\n    // Context sample\n    // File:Some.php:10\n    'label' => '🚩Label',\n];\n");

    $this->artisan('vox:translate', ['--path' => 'en/sample.php', '--no-interaction' => true])->assertExitCode(0);

    $contents = File::get($targetRoot.'/lang/fr/sample.php');

    expect($contents)->toContain('// Context sample')
        ->and($contents)->toContain('// File:Some.php:10');
});

it('preserves inline comments for multiline keys when translating group files', function () {
    $targetRoot = prepareVoxFixtures();

    config()->set('vox.translate.driver', EchoTranslationDriver::class);

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
});

it('translates only the requested key when key option is provided', function () {
    $targetRoot = prepareVoxFixtures();

    config()->set('vox.translate.driver', EchoTranslationDriver::class);

    File::put($targetRoot.'/lang/en/sample.php', "<?php\n\nreturn [\n    'count' => 'Count :count',\n    'title' => 'Title',\n];\n");
    File::put($targetRoot.'/lang/fr/sample.php', "<?php\n\nreturn [\n    'count' => '🚩Count :count',\n    'title' => '🚩Title',\n];\n");

    $this->artisan('vox:translate', ['--path' => 'en/sample.php', '--key' => 'count', '--no-interaction' => true])->assertExitCode(0);

    $frSample = require $targetRoot.'/lang/fr/sample.php';

    expect($frSample['count'])->toBe('Count :count')
        ->and($frSample['title'])->toBe('🚩Title');
});

it('forces retranslation for a requested key', function () {
    $targetRoot = prepareVoxFixtures();

    config()->set('vox.translate.driver', EchoTranslationDriver::class);

    File::put($targetRoot.'/lang/en/sample.php', "<?php\n\nreturn [\n    'count' => 'Count :count',\n    'title' => 'Title',\n];\n");
    File::put($targetRoot.'/lang/fr/sample.php', "<?php\n\nreturn [\n    'count' => 'Nombre :count',\n    'title' => '🚩Title',\n];\n");

    $this->artisan('vox:translate', ['--path' => 'en/sample.php', '--key' => 'count', '--force' => true, '--no-interaction' => true])->assertExitCode(0);

    $frSample = require $targetRoot.'/lang/fr/sample.php';

    expect($frSample['count'])->toBe('Count :count')
        ->and($frSample['title'])->toBe('🚩Title');
});

it('translates absent targets but keeps blank targets and skips unusable source wording even when forced', function (bool $force): void {
    $root = prepareVoxFixtures();
    config()->set('vox.translate.driver', EchoTranslationDriver::class);
    config()->set('vox.parse.missing_translation_prefix', 'TODO:');
    $source = ['absentTarget' => 'Hi', 'emptyTarget' => 'Hello', 'emptySource' => '', 'flaggedSource' => 'TODO:source', 'ready' => 'Ready'];
    $target = ['emptyTarget' => '', 'emptySource' => 'TODO:empty', 'flaggedSource' => 'TODO:flagged', 'ready' => 'Prêt'];
    File::put($root.'/lang/en/check.php', '<?php return '.var_export($source, true).';');
    File::put($root.'/lang/fr/check.php', '<?php return '.var_export($target, true).';');
    $this->artisan('vox:translate', ['--path' => 'en/check.php', '--force' => $force])->assertExitCode(0);
    expect(require $root.'/lang/fr/check.php')->toBe([
        'absentTarget' => 'Hi', 'emptySource' => 'TODO:empty', 'emptyTarget' => '', 'flaggedSource' => 'TODO:flagged',
        'ready' => $force ? 'Ready' : 'Prêt',
    ]);
})->with([false, true]);

it('preserves flat dotted keys without creating nested duplicates during translation', function (): void {
    $root = prepareVoxFixtures();
    config()->set('vox.translate.driver', EchoTranslationDriver::class);
    config()->set('vox.parse.output', 'flat');
    config()->set('vox.parse.preserve_existing_format', true);
    File::put($root.'/lang/en/check.php', "<?php return ['promo.line' => 'Hello'];");
    File::put($root.'/lang/fr/check.php', "<?php return ['promo.line' => '🚩Hello'];");
    $this->artisan('vox:translate', ['--path' => 'en/check.php'])->assertExitCode(0);
    expect(require $root.'/lang/fr/check.php')->toBe(['promo.line' => 'Hello']);
});

it('uses shared missing and source checks for root and vendor JSON translations', function (bool $vendor): void {
    $root = prepareVoxFixtures();
    config()->set('vox.translate.driver', EchoTranslationDriver::class);
    $directory = $root.'/lang'.($vendor ? '/vendor/check' : '');
    File::ensureDirectoryExists($directory);
    File::put($directory.'/en.json', json_encode(['Hello' => 'Hello', 'Blank' => 'Kept blank', 'Empty' => '', 'Flagged' => '🚩source']));
    File::put($directory.'/fr.json', json_encode(['Hello' => '🚩Hello', 'Blank' => '', 'Empty' => '🚩empty', 'Flagged' => '🚩flagged']));
    $this->artisan('vox:translate', ['--path' => ($vendor ? 'vendor/check/' : '').'en.json'])->assertExitCode(0);
    expect(json_decode(File::get($directory.'/fr.json'), true))->toBe([
        'Blank' => '', 'Empty' => '🚩empty', 'Flagged' => '🚩flagged', 'Hello' => 'Hello',
    ]);
})->with([false, true]);

it('saves completed translations and stops cleanly when the provider fails part-way', function (): void {
    $root = prepareVoxFixtures();
    config()->set('vox.translate.driver', 'openai');
    config()->set('vox.translate.providers.openai.api_key', 'test-key');
    File::put($root.'/lang/en/check.php', "<?php return ['first' => 'First', 'second' => 'Second', 'third' => 'Third'];");
    File::put($root.'/lang/fr/check.php', "<?php return ['first' => '🚩First', 'second' => '🚩Second', 'third' => '🚩Third'];");
    Http::fakeSequence()
        ->push(['output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'Premier']]]]])
        ->push(['error' => ['message' => 'You exceeded your current quota.']], 429);

    $this->artisan('vox:translate', ['--path' => 'en/check.php'])
        ->expectsOutputToContain('Stopped early: OpenAI request failed (HTTP 429): You exceeded your current quota.')
        ->assertFailed();

    expect(require $root.'/lang/fr/check.php')->toBe(['first' => 'Premier', 'second' => '🚩Second', 'third' => '🚩Third']);
    Http::assertSentCount(2);
});
