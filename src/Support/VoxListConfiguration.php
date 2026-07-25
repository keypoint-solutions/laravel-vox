<?php

namespace KeypointSolutions\LaravelVox\Support;

final class VoxListConfiguration
{
    public const MODE_AUTO = 'auto';

    public const MODE_CONFIGURED = 'configured';

    public function mode(string $key): string
    {
        return config("{$key}.mode") === self::MODE_CONFIGURED
            ? self::MODE_CONFIGURED
            : self::MODE_AUTO;
    }

    /**
     * @return array<int, string>|null
     */
    public function configuredValues(string $key): ?array
    {
        if ($this->mode($key) !== self::MODE_CONFIGURED) {
            return null;
        }

        $values = config("{$key}.values", []);

        if (is_string($values)) {
            $values = explode(',', $values);
        }

        if (! is_array($values)) {
            return [];
        }

        return array_values(array_unique(array_filter(
            array_map(
                static fn (mixed $value): string => is_string($value) ? trim($value) : '',
                $values
            ),
            static fn (string $value): bool => $value !== ''
        )));
    }
}
