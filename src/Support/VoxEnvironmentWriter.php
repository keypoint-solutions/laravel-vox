<?php

namespace KeypointSolutions\LaravelVox\Support;

use Illuminate\Support\Facades\File;

class VoxEnvironmentWriter
{
    public function set(string $key, mixed $value): bool
    {
        $envPath = base_path('.env');

        if (! File::exists($envPath)) {
            return false;
        }

        $envContent = File::get($envPath);
        $envValue = $this->formatValue($value);
        $pattern = '/^'.preg_quote($key, '/').'=.*/m';

        if (preg_match($pattern, $envContent)) {
            $updatedContent = preg_replace($pattern, "{$key}={$envValue}", $envContent);

            if (! is_string($updatedContent)) {
                return false;
            }
        } else {
            $updatedContent = rtrim($envContent)."\n{$key}={$envValue}\n";
        }

        return File::put($envPath, $updatedContent) !== false;
    }

    private function formatValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_numeric($value)) {
            return (string) $value;
        }

        $stringValue = (string) $value;

        if ($stringValue === '' || preg_match('/[\s#"\'\\\\]/', $stringValue)) {
            return '"'.addcslashes($stringValue, '"\\').'"';
        }

        return $stringValue;
    }
}
