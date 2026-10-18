<?php

namespace KeypointSolutions\LaravelVox;

use Illuminate\Support\Facades\Route;
use KeypointSolutions\LaravelVox\Translation\Locales\VoxLocaleDeclaration;
use KeypointSolutions\LaravelVox\Translation\Scanning\VoxDynamicKeyRegistry;

class LaravelVox
{
    public function __construct(
        private VoxDynamicKeyRegistry $dynamicKeys,
        private VoxLocaleDeclaration $localeDeclaration,
    ) {}

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

    /**
     * Declare the application's locales with a callback, used when `vox.translate.locales.mode` is `callback`.
     *
     * @param  callable(): iterable<int, string>  $callback
     */
    public function resolveLocalesUsing(callable $callback): void
    {
        $this->localeDeclaration->using($callback);
    }
}
