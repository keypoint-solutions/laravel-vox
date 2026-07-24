<?php

use KeypointSolutions\LaravelVox\Facades\LaravelVox;

if (config('vox.gui.enabled') && config('vox.routes.auto_register', true)) {
    LaravelVox::routes(config('vox.routes.prefix', 'vox'));
}
