<?php

use Illuminate\Support\Facades\File;
use KeypointSolutions\LaravelVox\Translation\TranslationFileWriter;
use KeypointSolutions\LaravelVox\Translation\TranslationScanner;

it('retains dynamic keys in non-base locales when keep_orphan_other_locales_keys is false', function () {
    $targetRoot = prepareVoxFixtures();

    config()->set('vox.parse.keep_orphan_other_locales_keys', false);
    config()->set('vox.dynamic_keys.patterns', ['messages.*']);

    $frMessagesPath = $targetRoot.'/lang/fr/messages.php';
    $frMessages = require $frMessagesPath;
    $frMessages['test'] = 'Test';
    File::put($frMessagesPath, "<?php\n\nreturn ".var_export($frMessages, true).";\n");

    $this->artisan('vox:parse', ['--no-interaction' => true])->assertExitCode(0);

    $updated = require $frMessagesPath;
    expect($updated)->toHaveKey('test', 'Test');

    File::deleteDirectory($targetRoot);
});

it('keeps obsolete comment lines between parse runs', function () {
    $targetRoot = prepareVoxFixtures();

    config()->set('vox.parse.obsolete', 'comment');

    $writer = new TranslationFileWriter;
    $authPath = $targetRoot.'/lang/en/auth.php';

    $this->artisan('vox:parse', ['--no-interaction' => true])->assertExitCode(0);
    $contents = File::get($authPath);

    expect($contents)->toContain($writer->obsoleteCommentPrefix())
        ->and($contents)->toContain('"obsolete" =>');

    $this->artisan('vox:parse', ['--no-interaction' => true])->assertExitCode(0);
    $contents = File::get($authPath);

    expect($contents)->toContain($writer->obsoleteCommentPrefix())
        ->and($contents)->toContain('"obsolete" =>');

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

it('parses Laravel and frontend plural translation helper variants', function (): void {
    $targetRoot = prepareVoxFixtures();
    $jsPath = $targetRoot.'/resources/js/app.js';
    $bladePath = $targetRoot.'/resources/views/plural-variants.blade.php';

    File::append(
        $jsPath,
        implode("\n", [
            '',
            "transChoice('frontend.Function items selected', 2);",
            "trans_choice('frontend.Alias items selected', 2);",
            "wTransChoice('frontend.Reactive items selected', 2);",
            "\$tChoice('frontend.Template items selected', 2);",
            "i18n.transChoice('frontend.Instance items selected', 2);",
            '',
        ])
    );
    File::put(
        $bladePath,
        <<<'BLADE'
{{ trans_choice('messages.Helper items selected', 2) }}
@choice('messages.Directive items selected', 2)
{{ Lang::choice('messages.Facade items selected', 2) }}
{{ app('translator')->choice('messages.Instance items selected', 2) }}
{{ trans()->choice('messages.Helper instance items selected', 2) }}
BLADE
    );

    $this->artisan('vox:parse', ['--no-interaction' => true])->assertExitCode(0);

    $frontend = require $targetRoot.'/lang/en/frontend.php';
    $messages = require $targetRoot.'/lang/en/messages.php';

    expect($frontend)
        ->toHaveKey('Function items selected', 'Function items selected')
        ->toHaveKey('Alias items selected', 'Alias items selected')
        ->toHaveKey('Reactive items selected', 'Reactive items selected')
        ->toHaveKey('Template items selected', 'Template items selected')
        ->toHaveKey('Instance items selected', 'Instance items selected')
        ->and($messages)
        ->toHaveKey('Helper items selected', 'Helper items selected')
        ->toHaveKey('Directive items selected', 'Directive items selected')
        ->toHaveKey('Facade items selected', 'Facade items selected')
        ->toHaveKey('Instance items selected', 'Instance items selected')
        ->toHaveKey('Helper instance items selected', 'Helper instance items selected');

    File::deleteDirectory($targetRoot);
});

it('parses Laravel and frontend regular translation helper variants', function (): void {
    $targetRoot = prepareVoxFixtures();
    $jsPath = $targetRoot.'/resources/js/app.js';
    $bladePath = $targetRoot.'/resources/views/regular-variants.blade.php';

    File::append(
        $jsPath,
        implode("\n", [
            '',
            "trans('frontend.Function regular translation');",
            "wTrans('frontend.Reactive regular translation');",
            "\$t('frontend.Template regular translation');",
            "i18n.trans('frontend.Instance regular translation');",
            "__('frontend.Nova regular translation');",
            "trans('frontend.It\\'s an escaped frontend translation');",
            '',
        ])
    );
    File::put(
        $bladePath,
        <<<'BLADE'
{{ trans('messages.Helper regular translation') }}
@lang('messages.Directive regular translation')
{{ Lang::get('messages.Facade regular translation') }}
{{ Lang::string('messages.Typed facade regular translation') }}
{{ app('translator')->get('messages.Container regular translation') }}
{{ app("translator")->string('messages.Typed container regular translation') }}
{{ trans()->get('messages.Instance regular translation') }}
{{ trans()->string('messages.Typed instance regular translation') }}
@lang(
    "If you're having trouble clicking the \":actionText\" button, copy and paste the URL below\n".
    'into your web browser:',
    ['actionText' => 'Open']
)
BLADE
    );

    $this->artisan('vox:parse', ['--no-interaction' => true])->assertExitCode(0);

    $frontend = require $targetRoot.'/lang/en/frontend.php';
    $messages = require $targetRoot.'/lang/en/messages.php';
    $json = json_decode(File::get($targetRoot.'/lang/en.json'), true, flags: JSON_THROW_ON_ERROR);
    $manifest = json_decode(File::get($targetRoot.'/dynamic.json'), true, flags: JSON_THROW_ON_ERROR);
    $mailFallback = "If you're having trouble clicking the \":actionText\" button, copy and paste the URL below\n"
        .'into your web browser:';
    $truncatedMailFallback = "If you're having trouble clicking the ".'\\';
    $truncatedFrontendTranslation = 'It'.'\\';

    expect($frontend)
        ->toHaveKey('Function regular translation', 'Function regular translation')
        ->toHaveKey('Reactive regular translation', 'Reactive regular translation')
        ->toHaveKey('Template regular translation', 'Template regular translation')
        ->toHaveKey('Instance regular translation', 'Instance regular translation')
        ->toHaveKey('Nova regular translation', 'Nova regular translation')
        ->toHaveKey("It's an escaped frontend translation", "It's an escaped frontend translation")
        ->not->toHaveKey($truncatedFrontendTranslation)
        ->and($messages)
        ->toHaveKey('Helper regular translation', 'Helper regular translation')
        ->toHaveKey('Directive regular translation', 'Directive regular translation')
        ->toHaveKey('Facade regular translation', 'Facade regular translation')
        ->toHaveKey('Typed facade regular translation', 'Typed facade regular translation')
        ->toHaveKey('Container regular translation', 'Container regular translation')
        ->toHaveKey('Typed container regular translation', 'Typed container regular translation')
        ->toHaveKey('Instance regular translation', 'Instance regular translation')
        ->toHaveKey('Typed instance regular translation', 'Typed instance regular translation')
        ->and($json)
        ->toHaveKey($mailFallback, $mailFallback)
        ->not->toHaveKey($truncatedMailFallback)
        ->and(array_column($manifest['patterns'], 'pattern'))
        ->not->toContain("If you're having trouble clicking");

    File::deleteDirectory($targetRoot);
});

it('retains translation subtrees retrieved through Laravel array calls', function (): void {
    $targetRoot = prepareVoxFixtures();
    $bladePath = $targetRoot.'/resources/views/array-variants.blade.php';
    $catalog = [
        'title' => 'Catalogue',
        'options' => [
            'first' => 'First option',
            'nested' => [
                'second' => 'Second option',
            ],
        ],
    ];

    File::put(
        $targetRoot.'/lang/en/catalog.php',
        "<?php\n\nreturn ".var_export($catalog, true).";\n"
    );
    File::put(
        $targetRoot.'/lang/fr/catalog.php',
        "<?php\n\nreturn ".var_export($catalog, true).";\n"
    );
    File::put(
        $bladePath,
        <<<'BLADE'
{{ __('catalog.title') }}
{{ Lang::array('catalog.options') }}
{{ app('translator')->array('catalog.options') }}
{{ trans()->array('catalog.options') }}
BLADE
    );

    $this->artisan('vox:parse', ['--no-interaction' => true])->assertExitCode(0);

    $updated = require $targetRoot.'/lang/en/catalog.php';
    $manifest = json_decode(File::get($targetRoot.'/dynamic.json'), true, flags: JSON_THROW_ON_ERROR);

    expect($updated)
        ->toHaveKey('title', 'Catalogue')
        ->toHaveKey('options.first', 'First option')
        ->toHaveKey('options.nested.second', 'Second option')
        ->and(array_column($manifest['patterns'], 'pattern'))
        ->toContain('catalog.options.*');

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
    $dynamicKeys = $scanner->dynamicKeys();
    $prefixes = array_column($dynamicKeys, 'prefix');
    $patterns = array_column($dynamicKeys, 'pattern');

    expect($prefixes)->toContain('frontend.dynamicLabels.values.')
        ->and($prefixes)->toContain('frontend.dynamicLabels2.values.')
        ->and($patterns)->toContain('frontend.dynamicLabels.values.*')
        ->and($patterns)->toContain('frontend.dynamicLabels2.values.*');

    File::deleteDirectory($targetRoot);
});

it('detects dynamic plural keys across Laravel and frontend variants', function (): void {
    $targetRoot = prepareVoxFixtures();

    File::append(
        $targetRoot.'/resources/js/Welcome.vue',
        <<<'VUE'

transChoice(`frontend.choice.${key}`, count);
wTransChoice('frontend.reactive.' + key, count);
VUE
    );
    File::append(
        $targetRoot.'/resources/views/welcome.blade.php',
        <<<'BLADE'

@choice('messages.choice.' . $key, 2)
{{ Lang::choice('messages.facade.' . $key, 2) }}
{{ trans()->choice('messages.translator.' . $key, 2) }}
BLADE
    );

    $scanner = new TranslationScanner(
        base_path(),
        [$targetRoot.'/resources'],
        [],
        ['vue', 'blade.php'],
        1
    );

    $scanner->scan();
    $patterns = array_column($scanner->dynamicKeys(), 'pattern');

    expect($patterns)
        ->toContain('frontend.choice.*')
        ->toContain('frontend.reactive.*')
        ->toContain('messages.choice.*')
        ->toContain('messages.facade.*')
        ->toContain('messages.translator.*');

    File::deleteDirectory($targetRoot);
});

it('detects dynamic regular keys across Laravel and frontend variants', function (): void {
    $targetRoot = prepareVoxFixtures();

    File::append(
        $targetRoot.'/resources/js/Welcome.vue',
        <<<'VUE'

__(`frontend.nova.${key}`);
VUE
    );
    File::append(
        $targetRoot.'/resources/views/welcome.blade.php',
        <<<'BLADE'

@lang('messages.directive.' . $key)
{{ Lang::get('messages.facade.' . $key) }}
{{ Lang::string('messages.typed.' . $key) }}
{{ app('translator')->get('messages.container.' . $key) }}
{{ trans()->string('messages.instance.' . $key) }}
BLADE
    );

    $scanner = new TranslationScanner(
        base_path(),
        [$targetRoot.'/resources'],
        [],
        ['vue', 'blade.php'],
        1
    );

    $scanner->scan();
    $patterns = array_column($scanner->dynamicKeys(), 'pattern');

    expect($patterns)
        ->toContain('frontend.nova.*')
        ->toContain('messages.directive.*')
        ->toContain('messages.facade.*')
        ->toContain('messages.typed.*')
        ->toContain('messages.container.*')
        ->toContain('messages.instance.*');

    File::deleteDirectory($targetRoot);
});
