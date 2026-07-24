<?php

namespace KeypointSolutions\LaravelVox\Support;

use Illuminate\Support\Str;

class VoxKeyProtector
{
    /**
     * @param  array<int, string>  $protectedKeys
     */
    public function __construct(private array $protectedKeys) {}

    public function isProtected(string $key, ?string $group): bool
    {
        foreach ($this->protectedKeys as $protected) {
            if ($protected === '') {
                continue;
            }

            if (Str::startsWith($protected, '.')) {
                $protectedKey = ltrim($protected, '.');

                if ($protectedKey === '') {
                    continue;
                }

                if (Str::endsWith($protectedKey, '.') && Str::startsWith($key, $protectedKey)) {
                    return true;
                }

                if ($key === $protectedKey) {
                    return true;
                }

                continue;
            }

            if (preg_match('/^[^\s.]+\./', $protected) === 1) {
                [$protectedGroup, $protectedKey] = explode('.', $protected, 2);

                if ($protectedKey === '') {
                    if ($group === $protectedGroup) {
                        return true;
                    }

                    continue;
                }

                if ($group === $protectedGroup && Str::endsWith($protectedKey, '.') && Str::startsWith($key, $protectedKey)) {
                    return true;
                }

                if ($group === $protectedGroup && $protectedKey === $key) {
                    return true;
                }

                continue;
            }

            if ($group === null && $key === $protected) {
                return true;
            }
        }

        return false;
    }
}
