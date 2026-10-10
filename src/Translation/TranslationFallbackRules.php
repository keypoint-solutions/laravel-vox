<?php

namespace KeypointSolutions\LaravelVox\Translation;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use KeypointSolutions\LaravelVox\Models\VoxTranslationRule;
use KeypointSolutions\LaravelVox\Support\VoxConfig;
use KeypointSolutions\LaravelVox\Translation\Files\TranslationFileTransaction;
use KeypointSolutions\LaravelVox\Translation\Locales\VoxLocaleResolver;
use RuntimeException;

class TranslationFallbackRules
{
    public const MANIFEST = 'vox-fallback.json';

    private ?array $rules = null;

    private ?array $index = null;

    public function clear(): void
    {
        $this->rules = null;
        $this->index = null;
    }

    /** @return array<int, array<string, mixed>> */
    public function all(): array
    {
        return $this->rules ??= Schema::connection(VoxConfig::connectionName())->hasTable('vox_translation_rules')
            ? VoxTranslationRule::query()->orderBy('id')->get()->toArray() : [];
    }

    public function mode(string $locale, ?string $group = null, ?string $key = null, bool $published = false): string
    {
        if ($locale === app(VoxLocaleResolver::class)->resolveBaseLocale([])) {
            return 'translated';
        }
        foreach (['key', 'group', 'locale'] as $scope) {
            if (($scope === 'key' && $key === null) || ($scope === 'group' && $group === null)) {
                continue;
            }
            $mode = $this->selection($locale, $scope, $group, $key, $published);
            if ($mode !== 'inherit') {
                return $mode;
            }
        }

        return 'translated';
    }

    public function usesDefault(string $locale, ?string $group, ?string $key, bool $published = false): bool
    {
        return $this->mode($locale, $group ?? 'json', $key, $published) === 'default';
    }

    public function selection(string $locale, string $scope, ?string $group = null, ?string $key = null, bool $published = false): string
    {
        if ($this->index === null) {
            $this->index = [];
            foreach ($this->all() as $rule) {
                $this->index[$rule['locale'].':'.$rule['scope'].':'.$rule['target']] = $rule;
            }
        }
        $identity = $locale.':'.$scope.':'.self::target($scope, $group, $key);

        return $this->index[$identity][$published ? 'published_mode' : 'mode'] ?? 'inherit';
    }

    public function save(string $locale, string $scope, string $mode, ?string $group = null, ?string $key = null): void
    {
        if (! in_array($scope, ['locale', 'group', 'key'], true) || ! in_array($mode, ['inherit', 'translated', 'default'], true)) {
            throw new RuntimeException('Invalid fallback choice.');
        }
        if ($locale === app(VoxLocaleResolver::class)->resolveBaseLocale([])) {
            throw new RuntimeException('The default language cannot use a fallback rule.');
        }
        VoxTranslationRule::query()->updateOrCreate([
            'locale' => $locale, 'scope' => $scope, 'target' => self::target($scope, $group, $key),
        ], [
            'group' => $scope === 'locale' ? null : ($group ?? 'json'),
            'key' => $scope === 'key' ? $key : null,
            'mode' => $mode,
        ]);
        $this->clear();
    }

    public function publish(): void
    {
        foreach (VoxTranslationRule::query()->whereColumn('mode', '!=', 'published_mode')->get() as $rule) {
            $rule->published_mode = $rule->mode;
            $rule->save();
        }
        $this->clear();
    }

    public function writeManifest(string $path): ?string
    {
        $rules = array_map(fn (array $rule): array => array_intersect_key($rule, array_flip(['locale', 'scope', 'group', 'key', 'published_mode'])), $this->all());
        if ($rules === [] && ! File::exists($path.'/'.self::MANIFEST)) {
            return null;
        }
        $contents = json_encode(['version' => 1, 'rules' => $rules], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
        $file = $path.'/'.self::MANIFEST;
        if (File::exists($file) && File::get($file) === $contents) {
            return null;
        }
        app(TranslationFileTransaction::class)->replace($file, $contents);

        return $file;
    }

    /** @return array<int, array{locale: string, scope: string, group?: string|null, key?: string|null, published_mode: string}> */
    public static function validateManifest(string $contents): array
    {
        try {
            $data = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new RuntimeException('Invalid Vox fallback manifest.', previous: $exception);
        }
        if (! is_array($data) || ($data['version'] ?? null) !== 1 || ! is_array($data['rules'] ?? null)) {
            throw new RuntimeException('Invalid Vox fallback manifest.');
        }
        foreach ($data['rules'] as $rule) {
            if (! is_array($rule) || ! is_string($rule['locale'] ?? null)
                || preg_match('/^[A-Za-z]{2,3}(?:[_-][A-Za-z0-9]{2,8})*$/D', $rule['locale']) !== 1
                || ! in_array($rule['scope'] ?? null, ['locale', 'group', 'key'], true)
                || ! in_array($rule['published_mode'] ?? null, ['inherit', 'translated', 'default'], true)
                || (isset($rule['group']) && (! is_string($rule['group']) || strlen($rule['group']) > 255))
                || (isset($rule['key']) && ! is_string($rule['key']))
                || ($rule['scope'] !== 'locale' && ! is_string($rule['group'] ?? null))
                || ($rule['scope'] === 'key' && ! is_string($rule['key'] ?? null))) {
                throw new RuntimeException('Invalid Vox fallback rule.');
            }
        }

        return $data['rules'];
    }

    public function importManifest(string $path): void
    {
        if (! File::exists($path.'/'.self::MANIFEST)) {
            return;
        }
        foreach (self::validateManifest(File::get($path.'/'.self::MANIFEST)) as $rule) {
            if ($rule['locale'] === app(VoxLocaleResolver::class)->resolveBaseLocale([])) {
                continue;
            }
            $model = VoxTranslationRule::query()->firstOrNew([
                'locale' => $rule['locale'], 'scope' => $rule['scope'],
                'target' => self::target($rule['scope'], $rule['group'] ?? null, $rule['key'] ?? null),
            ]);
            if (! $model->exists || $model->mode === $model->published_mode) {
                $model->mode = $rule['published_mode'];
            }
            $model->fill([
                'group' => $rule['scope'] === 'locale' ? null : $rule['group'],
                'key' => $rule['scope'] === 'key' ? $rule['key'] : null,
                'published_mode' => $rule['published_mode'],
            ])->save();
        }
        $this->clear();
    }

    public function whereTranslated(Builder $query, string $locale): void
    {
        if ($locale === app(VoxLocaleResolver::class)->resolveBaseLocale([]) || $this->all() === []) {
            return;
        }
        $grammar = $query->getQuery()->getGrammar();
        $group = $grammar->wrap('vox_translations.group');
        $key = $grammar->wrap('vox_translations.key');
        $ruleGroup = $grammar->wrap('r.group');
        $ruleKey = $grammar->wrap('r.key');
        $query->whereRaw("COALESCE((SELECT r.mode FROM vox_translation_rules r WHERE r.locale = ? AND r.mode != 'inherit' AND (r.scope = 'locale' OR (r.scope = 'group' AND $ruleGroup = COALESCE($group, 'json')) OR (r.scope = 'key' AND $ruleGroup = COALESCE($group, 'json') AND $ruleKey = $key)) ORDER BY CASE r.scope WHEN 'key' THEN 0 WHEN 'group' THEN 1 ELSE 2 END LIMIT 1), 'translated') != 'default'", [$locale]);
    }

    private static function target(string $scope, ?string $group, ?string $key): string
    {
        return hash('sha256', json_encode([$scope === 'locale' ? null : ($group ?? 'json'), $scope === 'key' ? $key : null], JSON_THROW_ON_ERROR));
    }
}
