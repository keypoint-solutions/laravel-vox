<?php

use Illuminate\Support\Facades\File;

it('parses translation keys and updates lang files', function () {
    $targetRoot = prepareVoxFixtures();

    $this->artisan('vox:parse', ['--no-interaction' => true])->assertExitCode(0);

    $enMessages = require $targetRoot.'/lang/en/messages.php';
    $frMessages = require $targetRoot.'/lang/fr/messages.php';
    $enAuth = require $targetRoot.'/lang/en/auth.php';
    $enFrontend = require $targetRoot.'/lang/en/frontend.php';
    $frFrontend = require $targetRoot.'/lang/fr/frontend.php';
    $vendorEn = require $targetRoot.'/lang/vendor/vendorpkg/en/messages.php';
    $vendorFr = require $targetRoot.'/lang/vendor/vendorpkg/fr/messages.php';

    expect($enMessages)->toHaveKey('hello')
        ->and($frMessages)->toHaveKey('hello')
        ->and($frMessages['hello'])->toStartWith('🚩')
        ->and($enAuth)->not->toHaveKey('obsolete')
        ->and($enFrontend)->toHaveKey('welcome')
        ->and($frFrontend['welcome'])->toStartWith('🚩')
        ->and($vendorEn)->toHaveKey('title')
        ->and($vendorFr['title'])->toStartWith('🚩');

    $frJson = json_decode(File::get($targetRoot.'/lang/fr.json'), true);
    expect($frJson['Welcome'])->toStartWith('🚩');

    File::deleteDirectory($targetRoot);
});
