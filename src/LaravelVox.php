<?php

namespace KeypointSolutions\LaravelVox;

use Illuminate\Support\Facades\Route;

class LaravelVox
{
    public function routes(?string $prefix = null): void
    {
        /** @var array{as: string, prefix?: string} $attributes */
        $attributes = ['as' => 'vox.'];
        $prefix = trim((string) $prefix, '/');

        if ($prefix !== '') {
            $attributes['prefix'] = $prefix;
        }

        Route::group($attributes, __DIR__.'/../routes/vox.php');
    }
}
