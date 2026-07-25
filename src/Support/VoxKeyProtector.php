<?php

namespace KeypointSolutions\LaravelVox\Support;

/**
 * @deprecated Use VoxDynamicKeyRegistry.
 */
class VoxKeyProtector
{
    /**
     * @param  array<int, string>  $protectedKeys
     */
    public function __construct(private array $protectedKeys) {}

    public function isProtected(string $key, ?string $group): bool
    {
        foreach ($this->protectedKeys as $protected) {
            $fullKey = $group === null || $group === 'json' ? $key : $group.'.'.$key;

            if (VoxDynamicKeyRegistry::patternMatches($protected, $fullKey)) {
                return true;
            }
        }

        return false;
    }
}
