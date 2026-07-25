<?php

namespace KeypointSolutions\LaravelVox;

use Illuminate\Support\Facades\Route;
use KeypointSolutions\LaravelVox\Support\VoxDynamicKeyRegistry;

class LaravelVox
{
    public function __construct(private VoxDynamicKeyRegistry $dynamicKeys) {}

    public function routes(?string $prefix = null): void
    {
        $this->registerRoutes(__DIR__.'/../routes/vox.php', $prefix);
    }

    public function translationRoutes(?string $prefix = null): void
    {
        $this->registerRoutes(__DIR__.'/../routes/translations.php', $prefix);
    }

    private function registerRoutes(string $path, ?string $prefix): void
    {
        /** @var array{as: string, prefix?: string} $attributes */
        $attributes = ['as' => 'vox.'];
        $prefix = trim((string) $prefix, '/');

        if ($prefix !== '') {
            $attributes['prefix'] = $prefix;
        }

        Route::group($attributes, $path);
    }

    /**
     * @param  iterable<int, mixed>|callable|string  $source
     */
    public function dynamicKeys(string $pattern, iterable|callable|string $source): void
    {
        $this->dynamicKeys->bind($pattern, $source);
    }
}
