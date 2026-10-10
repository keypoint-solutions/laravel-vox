<?php

namespace KeypointSolutions\LaravelVox\Translation;

use Illuminate\Support\Str;
use KeypointSolutions\LaravelVox\Support\VoxConfig;

class TranslationEligibility
{
    /**
     * A value is missing when it was never provided or still carries the missing marker.
     * An empty string is deliberate wording: it is saved, approved and published like any other.
     */
    public function isMissing(mixed $value): bool
    {
        return ! is_string($value) || $this->isFlagged($value);
    }

    public function isFlagged(string $value): bool
    {
        $prefix = VoxConfig::missingPrefix();

        return $prefix !== '' && str_starts_with($value, $prefix);
    }

    public function isBlank(mixed $value): bool
    {
        return is_string($value) && trim($value) === '';
    }

    public function canTranslateSource(string $key, mixed $value): bool
    {
        return ! $this->isMissing($value) && ! $this->isBlank($value)
            && ! ($value === $key && $this->isPlaceholderKey($key));
    }

    public function isPlaceholderKey(string $key): bool
    {
        $segments = array_unique([$key, Str::afterLast($key, '.')]);

        foreach ($segments as $segment) {
            if ($this->segmentLooksPlaceholder($segment)) {
                return true;
            }
        }

        return false;
    }

    private function segmentLooksPlaceholder(string $segment): bool
    {
        $segment = trim($segment);

        if ($segment === '') {
            return false;
        }

        if (Str::contains($segment, ' ')) {
            return false;
        }

        $prefixes = config('vox.translate.placeholder_prefixes', []);

        if (! is_array($prefixes)) {
            $prefixes = [];
        }

        $lower = Str::lower($segment);

        foreach ($prefixes as $prefix) {
            if (! is_string($prefix) || $prefix === '') {
                continue;
            }

            if (Str::startsWith($lower, Str::lower($prefix))) {
                return true;
            }
        }

        if (preg_match('/[_-]/', $segment) === 1) {
            return true;
        }

        if (preg_match('/^[a-z]+[A-Z]/', $segment) === 1) {
            return true;
        }

        return preg_match('/\\d/', $segment) === 1;
    }
}
