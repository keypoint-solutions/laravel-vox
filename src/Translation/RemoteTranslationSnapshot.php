<?php

namespace KeypointSolutions\LaravelVox\Translation;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use KeypointSolutions\LaravelVox\Models\VoxTranslation;

class RemoteTranslationSnapshot
{
    public const FORMAT = 'vox-reconciliation-v1';

    /**
     * @return array{format: string, values: array<int, array{group: string, key: string, locale: string, value: string}>}
     */
    public function export(): array
    {
        $values = [];

        foreach (VoxTranslation::query()->where('is_orphan', false)->with('values')->orderBy('id')->get() as $translation) {
            foreach ($translation->values as $value) {
                if ($value->is_obsolete) {
                    continue;
                }

                $values[] = [
                    'group' => $translation->group ?? 'json',
                    'key' => $translation->key,
                    'locale' => $value->locale,
                    'value' => $value->value,
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
        $validated = Validator::make(['values' => $values], [
            'values' => ['present', 'array', 'max:100000'],
            'values.*' => ['required', 'array:group,key,locale,value'],
            'values.*.group' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9][A-Za-z0-9_.-]*(?:::[A-Za-z0-9][A-Za-z0-9_.-]*)?$/D'],
            'values.*.key' => ['required', 'string', 'max:255'],
            'values.*.locale' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z]{2,3}(?:[_-][A-Za-z0-9]{2,8})*$/D'],
            'values.*.value' => ['present', 'string', 'max:1000000'],
        ])->validate()['values'];
        $seen = [];

        foreach ($validated as $value) {
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

        return array_values($validated);
    }

    public static function identity(?string $group, string $key, string $locale = ''): string
    {
        return hash('sha256', json_encode([$group ?? 'json', $key, $locale], JSON_THROW_ON_ERROR));
    }
}
