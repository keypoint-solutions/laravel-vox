<?php

namespace KeypointSolutions\LaravelVox;

interface DynamicKeyProvider
{
    /**
     * @return iterable<int, string|int|\UnitEnum>
     */
    public function values(): iterable;
}
