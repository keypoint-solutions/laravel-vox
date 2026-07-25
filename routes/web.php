<?php

use KeypointSolutions\LaravelVox\Facades\LaravelVox;

if (config('vox.routes.auto_register', true)) {
    if (config('vox.gui.enabled')) {
        LaravelVox::routes(config('vox.routes.prefix', 'vox'));
    } elseif (config('vox.frontend.runtime.enabled', false)) {
        LaravelVox::translationRoutes(config('vox.routes.prefix', 'vox'));
    }
}
