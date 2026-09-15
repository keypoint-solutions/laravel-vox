<?php

namespace KeypointSolutions\LaravelVox\Translation;

use Illuminate\Support\Arr;

class TranslationGroupFormat
{
    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function normalize(array $data): array
    {
        $flat = Arr::dot($data);
        if (config('vox.parse.output', 'flat') === 'flat' && ! config('vox.parse.preserve_existing_format', true)) {
            return $flat;
        }

        foreach ($flat as $key => $value) {
            $this->set($data, (string) $key, $value);
        }

        return $data;
    }

    /** @param array<string, mixed> $data */
    public function set(array &$data, string $key, mixed $value): void
    {
        $withoutFlatKey = $data;
        unset($withoutFlatKey[$key]);
        $nested = config('vox.parse.output', 'flat') === 'nested'
            || (config('vox.parse.preserve_existing_format', true) && Arr::has($withoutFlatKey, $key));

        if ($nested) {
            unset($data[$key]);
            Arr::set($data, $key, $value);

            return;
        }

        $data[$key] = $value;
    }

    /** @param array<string, mixed> $values */
    public function remove(array &$values, string $key): void
    {
        unset($values[$key]);
        foreach ($values as $segment => &$value) {
            if (is_array($value) && str_starts_with($key, $segment.'.')) {
                $this->remove($value, substr($key, strlen($segment) + 1));
                if ($value === []) {
                    unset($values[$segment]);
                }
            }
        }
    }
}
