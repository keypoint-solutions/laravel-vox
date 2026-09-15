<?php

namespace KeypointSolutions\LaravelVox\Translation;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;
use KeypointSolutions\LaravelVox\Models\VoxTranslation;

class RemoteTranslationSnapshot
{
    public const FORMAT = 'vox-reconciliation-v2';

    /**
     * @return array{format: string, values: array<int, array{group: string, key: string, locale: string, value: string}>}
     */
    public function export(bool $includeDrafts = false): array
    {
        $values = [];

        foreach (VoxTranslation::query()->where('is_orphan', false)->with('values')->lazyById(100) as $translation) {
            foreach ($translation->values as $value) {
                if ($value->is_obsolete) {
                    continue;
                }

                $published = $value->liveValue();
                if (! $includeDrafts && $published === null) {
                    continue;
                }
                $values[] = [
                    'group' => $translation->group ?? 'json',
                    'key' => $translation->key,
                    'locale' => $value->locale,
                    'value' => $includeDrafts ? $value->value : $published,
                ];
            }
        }

        return ['format' => self::FORMAT, 'values' => $this->validate($values)];
    }

    /**
     * @return array<int, array{group: string, key: string, locale: string, value: string}>
     */
    public function fromDirectory(string $path): array
    {
        $values = [];

        foreach (File::allFiles($path) as $file) {
            $parts = explode('/', str_replace('\\', '/', $file->getRelativePathname()));
            $namespace = $parts[0] === 'vendor' ? $parts[1] : null;
            $filename = array_pop($parts);

            if ($file->getExtension() === 'php') {
                $locale = array_pop($parts);
                $group = ($namespace !== null ? $namespace.'::' : '').pathinfo($filename, PATHINFO_FILENAME);
                $entries = Arr::dot(require $file->getPathname());
            } else {
                $locale = pathinfo($filename, PATHINFO_FILENAME);
                $group = 'json';
                $entries = json_decode(File::get($file->getPathname()), true, flags: JSON_THROW_ON_ERROR);
            }

            foreach ($entries as $key => $value) {
                $values[] = [
                    'group' => $group,
                    'key' => $group === 'json' && $namespace !== null ? $namespace.'::'.$key : (string) $key,
                    'locale' => $locale,
                    'value' => $value,
                ];
            }
        }

        return $this->validate($values);
    }

    /**
     * @return array<int, array{group: string, key: string, locale: string, value: string}>
     */
    public function validate(mixed $values): array
    {
        if (! is_array($values) || count($values) > 100000) {
            throw ValidationException::withMessages(['sync' => 'Remote snapshot must contain an array of at most 100000 values.']);
        }

        $seen = [];

        foreach ($values as $value) {
            if (! is_array($value) || count($value) !== 4
                || ! isset($value['group'], $value['key'], $value['locale'], $value['value'])) {
                throw ValidationException::withMessages(['sync' => 'Each remote value must contain only group, key, locale, and value fields.']);
            }

            if (! is_string($value['group']) || strlen($value['group']) > 255
                || preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]*(?:::[A-Za-z0-9][A-Za-z0-9_.-]*)?$/D', $value['group']) !== 1) {
                throw ValidationException::withMessages(['sync' => 'Remote translation group is invalid.']);
            }

            if (! is_string($value['key']) || trim($value['key']) === '') {
                throw ValidationException::withMessages(['sync' => 'Remote translation key must be a non-empty string.']);
            }

            if (! is_string($value['locale']) || strlen($value['locale']) > 255
                || preg_match('/^[A-Za-z]{2,3}(?:[_-][A-Za-z0-9]{2,8})*$/D', $value['locale']) !== 1) {
                throw ValidationException::withMessages(['sync' => 'Remote translation locale is invalid.']);
            }

            if (! is_string($value['value']) || mb_strlen($value['value'], 'UTF-8') > 1000000) {
                throw ValidationException::withMessages(['sync' => 'Remote translation value must be a string of at most 1000000 characters.']);
            }

            if ($value['group'] === 'json' && str_contains($value['key'], '::')) {
                $namespace = explode('::', $value['key'], 2)[0];

                if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]*$/D', $namespace) !== 1) {
                    throw ValidationException::withMessages(['sync' => 'Remote JSON namespace is invalid.']);
                }
            }

            $identity = self::identity($value['group'], $value['key'], $value['locale']);

            if (isset($seen[$identity])) {
                throw ValidationException::withMessages(['sync' => 'Remote snapshot contains duplicate translation values.']);
            }

            $seen[$identity] = true;
        }

        return array_values($values);
    }

    public static function identity(?string $group, string $key, string $locale = ''): string
    {
        return hash('sha256', json_encode([$group ?? 'json', $key, $locale], JSON_THROW_ON_ERROR));
    }
}
