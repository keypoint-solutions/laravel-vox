<?php

namespace KeypointSolutions\LaravelVox\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static void routes(?string $prefix = null)
 * @method static void translationRoutes(?string $prefix = null)
 * @method static void dynamicKeys(string $pattern, iterable|callable|string $source)
 *
 * @see \KeypointSolutions\LaravelVox\LaravelVox
 */
class LaravelVox extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \KeypointSolutions\LaravelVox\LaravelVox::class;
    }
}
