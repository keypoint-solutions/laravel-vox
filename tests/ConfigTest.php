<?php

it('scans Laravel and optional Cashier sources by default', function () {
    $defaultConfig = require __DIR__.'/../config/vox.php';

    expect($defaultConfig['parse']['paths'])
        ->toContain('/vendor/laravel/framework/src')
        ->toContain('/vendor/laravel/cashier/src');
});
