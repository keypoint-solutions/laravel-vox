<?php

namespace KeypointSolutions\LaravelVox\Translation;

use Illuminate\Support\Str;

/**
 * Narrows a translation run to a single requested key, optionally scoped to a group or vendor JSON namespace.
 */
class TranslationKeyFilter
{
    public function __construct(
        public readonly ?string $key = null,
        public readonly ?string $group = null,
        public readonly ?string $jsonNamespace = null,
    ) {}

    /**
     * @param  array{type: string, group?: string, namespace?: string|null}|null  $target
     * @param  array<string, array<string, string>>  $baseGroups
     */
    public static function resolve(?string $requestedKey, ?array $target, array $baseGroups): self
    {
        if ($requestedKey === null) {
            return new self;
        }

        if ($target !== null) {
            return new self($target['type'] === 'group'
                ? self::stripGroupPrefix($requestedKey, $target['group'])
                : self::stripJsonNamespacePrefix($requestedKey, $target['namespace']));
        }

        if (Str::contains($requestedKey, '::')) {
            [$namespace, $rest] = explode('::', $requestedKey, 2);
            $rest = ltrim($rest, '.');

            if ($rest !== '' && Str::contains($rest, '.')) {
                [$groupName, $restKey] = explode('.', $rest, 2);
                $candidateGroup = $namespace.'::'.$groupName;

                if (array_key_exists($candidateGroup, $baseGroups)) {
                    return new self($restKey, $candidateGroup);
                }
            }

            return new self($rest, null, $namespace);
        }

        if (Str::contains($requestedKey, '.')) {
            [$groupName, $restKey] = explode('.', $requestedKey, 2);

            if (array_key_exists($groupName, $baseGroups)) {
                return new self($restKey, $groupName);
            }
        }

        return new self($requestedKey);
    }

    public function allowsGroup(string $group): bool
    {
        return $this->group === null || $group === $this->group;
    }

    public function allowsVendorJsonNamespace(string $namespace): bool
    {
        return $this->jsonNamespace === null || $namespace === $this->jsonNamespace;
    }

    public function allowsKey(string $key): bool
    {
        return $this->key === null || $key === $this->key;
    }

    public function allowsJsonKey(?string $namespace, string $key): bool
    {
        if ($this->key === null) {
            return true;
        }

        if ($this->jsonNamespace !== null && $namespace !== $this->jsonNamespace) {
            return false;
        }

        return $key === $this->key;
    }

    /**
     * @param  array<string, array<string, string>>  $baseGroups
     * @param  array<string, string>  $baseJson
     * @param  array<string, array<string, string>>  $baseVendorJson
     * @param  array{type: string, group?: string, namespace?: string|null}|null  $target
     */
    public function matchesAny(array $baseGroups, array $baseJson, array $baseVendorJson, ?array $target): bool
    {
        if ($this->key === null) {
            return true;
        }

        if ($target !== null) {
            if ($target['type'] === 'group') {
                return array_key_exists($this->key, $baseGroups[$target['group']] ?? []);
            }

            if ($target['namespace'] !== null) {
                return array_key_exists($this->key, $baseVendorJson[$target['namespace']] ?? []);
            }

            return array_key_exists($this->key, $baseJson);
        }

        $groups = $this->group !== null ? [$baseGroups[$this->group] ?? []] : $baseGroups;

        foreach ($groups as $entries) {
            if (array_key_exists($this->key, $entries)) {
                return true;
            }
        }

        if ($this->jsonNamespace !== null) {
            return array_key_exists($this->key, $baseVendorJson[$this->jsonNamespace] ?? []);
        }

        if (array_key_exists($this->key, $baseJson)) {
            return true;
        }

        foreach ($baseVendorJson as $entries) {
            if (array_key_exists($this->key, $entries)) {
                return true;
            }
        }

        return false;
    }

    private static function stripGroupPrefix(string $key, string $group): string
    {
        $prefixes = [$group.'.'];
        $plainGroup = Str::contains($group, '::') ? Str::after($group, '::') : null;

        if ($plainGroup !== null && $plainGroup !== '') {
            $prefixes[] = $plainGroup.'.';
        }

        foreach ($prefixes as $prefix) {
            if (Str::startsWith($key, $prefix)) {
                return substr($key, strlen($prefix));
            }
        }

        return $key;
    }

    private static function stripJsonNamespacePrefix(string $key, ?string $namespace): string
    {
        if ($namespace === null) {
            return $key;
        }

        $prefix = $namespace.'::';

        return Str::startsWith($key, $prefix) ? substr($key, strlen($prefix)) : $key;
    }
}
