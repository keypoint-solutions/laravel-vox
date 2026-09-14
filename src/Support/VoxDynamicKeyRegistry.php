<?php

namespace KeypointSolutions\LaravelVox\Support;

use BackedEnum;
use Illuminate\Support\Facades\File;
use KeypointSolutions\LaravelVox\DynamicKeyProvider;
use KeypointSolutions\LaravelVox\Translation\TranslationFileTransaction;
use KeypointSolutions\LaravelVox\Translation\TranslationKey;
use UnitEnum;

class VoxDynamicKeyRegistry
{
    /**
     * @var array<string, iterable<int, mixed>|callable|string>
     */
    private array $runtimeBindings = [];

    public function __construct(private VoxSettingsRepository $settings) {}

    /**
     * @param  iterable<int, mixed>|callable|string  $source
     */
    public function bind(string $pattern, iterable|callable|string $source): void
    {
        $pattern = self::normalizePattern($pattern);

        if ($pattern === '' || substr_count($pattern, '*') !== 1) {
            return;
        }

        $this->runtimeBindings[$pattern] = $source;
    }

    /**
     * @return array<int, string>
     */
    public function patterns(): array
    {
        return array_column($this->entries(), 'pattern');
    }

    /**
     * @return array<int, array{
     *     pattern: string,
     *     is_frontend: bool,
     *     sources: array<int, string>,
     *     mode: 'open'|'enumerated',
     *     provider: string|null,
     *     keys: array<int, string>,
     *     occurrences: array<int, array{file: string, line: int|null, source: string|null, context: string|null}>
     * }>
     */
    public function entries(): array
    {
        $entries = [];

        foreach ((array) config('vox.retained_keys', []) as $pattern) {
            $pattern = self::normalizePattern($pattern);
            if ($pattern !== '') {
                $entries[$pattern] = $this->emptyEntry($pattern);
                $entries[$pattern]['sources'][] = 'retained-config';
            }
        }

        foreach ($this->settings->configuredDynamicKeyPatterns() as $pattern) {
            $entries[$pattern] ??= $this->emptyEntry($pattern);
            $entries[$pattern]['sources'][] = 'config';
        }

        foreach ($this->settings->editableDynamicKeyPatterns() as $pattern) {
            $entries[$pattern] ??= $this->emptyEntry($pattern);
            $entries[$pattern]['sources'][] = 'settings';
        }

        foreach ($this->bindings() as $pattern => $source) {
            $entries[$pattern] ??= $this->emptyEntry($pattern);
            $entries[$pattern]['mode'] = 'enumerated';
            $entries[$pattern]['provider'] = is_string($source) ? $source : null;
            $entries[$pattern]['keys'] = $this->keysFromSource($pattern, $source);
            $entries[$pattern]['sources'][] = 'binding';
        }

        foreach ($this->detectedPatterns() as $detected) {
            $pattern = $detected['pattern'];
            $entries[$pattern] ??= $this->emptyEntry($pattern);
            $entries[$pattern]['is_frontend'] = $entries[$pattern]['is_frontend'] || $detected['is_frontend'];
            $entries[$pattern]['sources'][] = 'detected';
            $entries[$pattern]['occurrences'] = array_merge(
                $entries[$pattern]['occurrences'],
                $detected['occurrences']
            );
        }

        foreach ($entries as &$entry) {
            $entry['sources'] = array_values(array_unique($entry['sources']));
        }

        unset($entry);

        ksort($entries);

        return array_values($entries);
    }

    /**
     * @return array{
     *     pattern: string,
     *     is_frontend: bool,
     *     sources: array<int, string>,
     *     occurrences: array<int, array{file: string, line: int|null, source: string|null, context: string|null}>
     * }|null
     */
    public function match(string $key, ?string $group): ?array
    {
        $fullKey = $this->fullKey($key, $group);
        $matches = array_values(array_filter(
            $this->entries(),
            fn (array $entry): bool => $this->entryMatches($entry, $fullKey)
        ));

        if ($matches === []) {
            return null;
        }

        return [
            'pattern' => $matches[0]['pattern'],
            'patterns' => array_column($matches, 'pattern'),
            'is_frontend' => collect($matches)->contains(
                fn (array $entry): bool => $entry['is_frontend']
            ),
            'sources' => array_values(array_unique(array_merge(...array_column($matches, 'sources')))),
            'occurrences' => array_merge(...array_column($matches, 'occurrences')),
        ];
    }

    public function matches(string $key, ?string $group): bool
    {
        return $this->match($key, $group) !== null;
    }

    public function patternAccepts(string $pattern, string $fullKey): bool
    {
        $entry = collect($this->entries())->firstWhere('pattern', self::normalizePattern($pattern));

        return is_array($entry) && $this->entryMatches($entry, $fullKey);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function enumeratedScanResults(): array
    {
        $results = [];

        foreach ($this->entries() as $entry) {
            if ($entry['mode'] !== 'enumerated') {
                continue;
            }

            foreach ($entry['keys'] as $fullKey) {
                $translationKey = TranslationKey::fromRaw($fullKey);
                $results[$translationKey->fullKey()] = [
                    'group' => $translationKey->group,
                    'key' => $translationKey->key,
                    'source' => 'dynamic-binding',
                    'is_frontend' => $entry['is_frontend'],
                    'occurrences' => array_map(
                        static fn (array $occurrence): array => [
                            'file' => $occurrence['file'],
                            'line' => $occurrence['line'],
                            'before' => '',
                            'after' => $occurrence['context'] ?? '',
                        ],
                        $entry['occurrences']
                    ),
                ];
            }
        }

        return $results;
    }

    /**
     * @param  array<string, array<string, mixed>>  $scanResults
     * @return array<string, array<string, mixed>>
     */
    public function mergeEnumeratedScanResults(array $scanResults): array
    {
        foreach ($this->enumeratedScanResults() as $fullKey => $entry) {
            if (! isset($scanResults[$fullKey])) {
                $scanResults[$fullKey] = $entry;

                continue;
            }

            $scanResults[$fullKey]['is_frontend'] = ($scanResults[$fullKey]['is_frontend'] ?? false)
                || ($entry['is_frontend'] ?? false);
            $scanResults[$fullKey]['occurrences'] = array_merge(
                is_array($scanResults[$fullKey]['occurrences'] ?? null)
                    ? $scanResults[$fullKey]['occurrences']
                    : [],
                $entry['occurrences']
            );
        }

        return $scanResults;
    }

    /**
     * @param  array<int, array{
     *     pattern?: string,
     *     prefix: string,
     *     suffix: string,
     *     source: string|null,
     *     is_frontend: bool,
     *     file: string,
     *     line: int|null,
     *     context: string|null
     * }>  $dynamicKeys
     */
    public function writeDetectedPatterns(array $dynamicKeys): void
    {
        $patterns = [];

        foreach ($dynamicKeys as $dynamicKey) {
            $pattern = self::normalizePattern(
                $dynamicKey['pattern'] ?? $dynamicKey['prefix'].'*'.$dynamicKey['suffix']
            );

            if ($pattern === '') {
                continue;
            }

            $patterns[$pattern] ??= [
                'pattern' => $pattern,
                'is_frontend' => false,
                'occurrences' => [],
            ];
            $patterns[$pattern]['is_frontend'] = $patterns[$pattern]['is_frontend']
                || $dynamicKey['is_frontend'];
            $patterns[$pattern]['occurrences'][] = [
                'file' => $dynamicKey['file'],
                'line' => $dynamicKey['line'] ?? null,
                'source' => $dynamicKey['source'],
                'context' => $dynamicKey['context'] ?? null,
            ];
        }

        ksort($patterns);

        $path = $this->manifestPath();
        $directory = dirname($path);

        if (! File::isDirectory($directory)) {
            File::makeDirectory($directory, 0755, true);
        }

        app(TranslationFileTransaction::class)->replace($path, json_encode(
            ['patterns' => array_values($patterns)],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
        )."\n");
    }

    /**
     * @return array<int, array{
     *     pattern: string,
     *     is_frontend: bool,
     *     occurrences: array<int, array{file: string, line: int|null, source: string|null, context: string|null}>
     * }>
     */
    public function detectedPatterns(): array
    {
        $path = $this->manifestPath();

        if (! File::exists($path)) {
            return [];
        }

        $decoded = json_decode(File::get($path), true);
        $patterns = is_array($decoded) ? ($decoded['patterns'] ?? []) : [];

        if (! is_array($patterns)) {
            return [];
        }

        $normalized = [];

        foreach ($patterns as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $pattern = self::normalizePattern($entry['pattern'] ?? null);

            if ($pattern === '') {
                continue;
            }

            $occurrences = is_array($entry['occurrences'] ?? null)
                ? array_values(array_filter($entry['occurrences'], 'is_array'))
                : [];

            $normalized[] = [
                'pattern' => $pattern,
                'is_frontend' => ($entry['is_frontend'] ?? false) === true,
                'occurrences' => $occurrences,
            ];
        }

        return $normalized;
    }

    public static function normalizePattern(mixed $pattern): string
    {
        if (! is_string($pattern)) {
            return '';
        }

        $pattern = trim($pattern);

        if ($pattern === '') {
            return '';
        }

        return $pattern;
    }

    public static function patternMatches(string $pattern, string $fullKey): bool
    {
        $pattern = self::normalizePattern($pattern);

        if ($pattern === '') {
            return false;
        }

        $regex = str_replace('\*', '.*', preg_quote($pattern, '/'));

        return preg_match('/^'.$regex.'$/su', $fullKey) === 1;
    }

    public function fullKey(string $key, ?string $group): string
    {
        if ($group === null || $group === 'json') {
            return $key;
        }

        return $group.'.'.$key;
    }

    public function manifestPath(): string
    {
        return (string) config('vox.dynamic_keys.manifest', storage_path('vox/dynamic.json'));
    }

    /**
     * @return array{
     *     pattern: string,
     *     is_frontend: bool,
     *     sources: array<int, string>,
     *     mode: 'open'|'enumerated',
     *     provider: string|null,
     *     keys: array<int, string>,
     *     occurrences: array<int, array{file: string, line: int|null, source: string|null, context: string|null}>
     * }
     */
    private function emptyEntry(string $pattern): array
    {
        return [
            'pattern' => $pattern,
            'is_frontend' => false,
            'sources' => [],
            'mode' => 'open',
            'provider' => null,
            'keys' => [],
            'occurrences' => [],
        ];
    }

    /**
     * @return array<string, iterable<int, mixed>|callable|string>
     */
    private function bindings(): array
    {
        $configured = config('vox.dynamic_keys.bindings', []);

        if (! is_array($configured)) {
            $configured = [];
        }

        $configured = array_merge($configured, $this->runtimeBindings);
        $bindings = [];

        foreach ($configured as $pattern => $source) {
            $pattern = self::normalizePattern($pattern);

            if (
                $pattern === ''
                || substr_count($pattern, '*') !== 1
                || (! is_iterable($source) && ! is_callable($source) && ! is_string($source))
            ) {
                continue;
            }

            $bindings[$pattern] = $source;
        }

        return $bindings;
    }

    /**
     * @param  iterable<int, mixed>|callable|string  $source
     * @return array<int, string>
     */
    private function keysFromSource(string $pattern, iterable|callable|string $source): array
    {
        $keys = [];

        foreach ($this->resolveSourceValues($source) as $value) {
            if ($value instanceof UnitEnum) {
                $value = $value instanceof BackedEnum ? $value->value : $value->name;
            }

            if (! is_string($value) && ! is_int($value)) {
                continue;
            }

            $keys[] = str_replace('*', (string) $value, $pattern);
        }

        return array_values(array_unique($keys));
    }

    /**
     * @param  iterable<int, mixed>|callable|string  $source
     * @return iterable<int, mixed>
     */
    private function resolveSourceValues(iterable|callable|string $source): iterable
    {
        if (is_string($source) && enum_exists($source)) {
            return $source::cases();
        }

        if (is_string($source) && is_a($source, DynamicKeyProvider::class, true)) {
            return app($source)->values();
        }

        if (is_callable($source)) {
            $values = $source();

            return is_iterable($values) ? $values : [];
        }

        return is_iterable($source) ? $source : [];
    }

    /**
     * @param  array{pattern: string, mode: 'open'|'enumerated', keys: array<int, string>}  $entry
     */
    private function entryMatches(array $entry, string $fullKey): bool
    {
        if (! self::patternMatches($entry['pattern'], $fullKey)) {
            return false;
        }

        return $entry['mode'] === 'open' || in_array($fullKey, $entry['keys'], true);
    }
}
