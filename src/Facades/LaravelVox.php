<?php

namespace KeypointSolutions\LaravelVox\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \KeypointSolutions\LaravelVox\LaravelVox
 */
class LaravelVox extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \KeypointSolutions\LaravelVox\LaravelVox::class;
    }
}
