<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * @var array<int, string|null>
     */
    protected array $connectionsToTransact = [null, 'vox'];
}
