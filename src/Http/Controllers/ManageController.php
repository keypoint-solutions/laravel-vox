<?php

namespace KeypointSolutions\LaravelVox\Http\Controllers;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use KeypointSolutions\LaravelVox\Models\VoxAudit;
use KeypointSolutions\LaravelVox\Models\VoxTranslation;
use KeypointSolutions\LaravelVox\Support\VoxDynamicKeyRegistry;
use KeypointSolutions\LaravelVox\Support\VoxFrontendManifest;
use KeypointSolutions\LaravelVox\Support\VoxLocaleResolver;
use KeypointSolutions\LaravelVox\Translation\TranslationKey;

class ManageController
{
    public function __construct(
        private VoxLocaleResolver $localeResolver,
        private VoxFrontendManifest $frontendManifest,
        private VoxDynamicKeyRegistry $dynamicKeys,
    ) {}

    public function __invoke(Request $request): Response
    {
        $filters = $this->resolveFilters($request);
        [$locales, $baseLocale] = $this->resolveLocales();

        $lastSync = VoxAudit::query()
            ->whereIn('action', ['sync', 'sync-remote'])
            ->orderByDesc('created_at')
            ->first();
        $lastSyncBoundary = $this->syncBoundary($lastSync);

        $totalTranslations = VoxTranslation::query()->count();

        return Inertia::render('Manage', [
            'groups' => $this->loadGroups(),
            'translations' => $this->loadTranslations($request, $filters, $locales, $lastSyncBoundary),
            'locales' => $locales,
            'baseLocale' => $baseLocale,
            'filters' => $filters,
            'statusOptions' => $this->statusOptions(),
            'sortOptions' => $this->sortOptions(),
            'lastSyncAt' => $lastSync?->created_at?->toIso8601String(),
            'ai' => $this->aiStatus(),
            'totalTranslations' => $totalTranslations,
            'dynamicPatterns' => array_values(array_filter(
                $this->dynamicKeys->entries(),
                fn (array $entry): bool => str_contains($entry['pattern'], '*')
                    && $entry['mode'] === 'open'
            )),
        ]);
    }

    /**
     * @return array{group: string|null, search: string, status: string|null, sort: string, scope: string}
     */
    private function resolveFilters(Request $request): array
    {
        $group = $request->input('group');
        $group = is_string($group) && $group !== '' ? $group : null;

        $search = $request->input('search');
        $search = is_string($search) ? trim($search) : '';

        $status = $request->input('status');
        $status = is_string($status) ? $status : null;

        $sort = $request->input('sort');
        $sort = is_string($sort) ? $sort : 'updated_desc';

        $scope = $request->input('scope');
        $scope = is_string($scope) ? $scope : null;

        $allowedStatus = ['new', 'updated', 'pending', 'approved', 'missing', 'orphan', 'dynamic'];
        if (! in_array($status, $allowedStatus, true)) {
            $status = null;
        }

        $allowedSort = ['updated_desc', 'status'];
        if (! in_array($sort, $allowedSort, true)) {
            $sort = 'updated_desc';
        }

        if ($scope !== 'all' && $scope !== 'group') {
            $scope = $group !== null ? 'group' : 'all';
        }

        return [
            'group' => $group,
            'search' => $search,
            'status' => $status,
            'sort' => $sort,
            'scope' => $scope,
        ];
    }

    /**
     * @return array{0: array<int, string>, 1: string}
     */
    private function resolveLocales(): array
    {
        $locales = $this->localeResolver->resolveLocales();
        $baseLocale = $this->localeResolver->resolveBaseLocale($locales);

        if (! in_array($baseLocale, $locales, true)) {
            $locales[] = $baseLocale;
        }

        $locales = array_values(array_unique($locales));
        $locales = array_values(array_unique(array_merge([$baseLocale], $locales)));

        return [$locales, $baseLocale];
    }

    /**
     * @return array<int, array{name: string, total: int, is_frontend_exported: bool, frontend_export_source: string|null, is_json: bool}>
     */
    private function loadGroups(): array
    {
        $frontendGroups = $this->frontendManifest->groups();
        $frontendSource = $this->frontendManifest->usesConfiguredGroups() ? 'configured' : 'detected';
        $groups = VoxTranslation::query()
            ->select('group')
            ->selectRaw('count(*) as total')
            ->groupBy('group')
            ->orderBy('group')
            ->get();

        return $groups
            ->map(function (VoxTranslation $group) use ($frontendGroups, $frontendSource): array {
                $name = $group->group ?? 'default';
                $isJson = $name === 'json';
                $isFrontendExported = $isJson || in_array($name, $frontendGroups, true);

                return [
                    'name' => $name,
                    'total' => (int) $group->total,
                    'is_frontend_exported' => $isFrontendExported,
                    'frontend_export_source' => $isFrontendExported
                        ? ($isJson ? 'json' : $frontendSource)
                        : null,
                    'is_json' => $isJson,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  array{group: string|null, search: string, status: string|null, sort: string, scope: string}  $filters
     * @param  array<int, string>  $locales
     */
    private function loadTranslations(
        Request $request,
        array $filters,
        array $locales,
        ?CarbonInterface $lastSyncAt
    ): LengthAwarePaginator {
        $perPage = (int) $request->input('per_page', 25);
        if ($perPage < 1) {
            $perPage = 25;
        }

        $perPage = min($perPage, 100);

        $query = VoxTranslation::query()
            ->with([
                'values' => fn ($builder) => $builder->orderBy('locale'),
                'occurrences' => fn ($builder) => $builder->orderBy('id'),
            ]);

        $applyGroup = $filters['scope'] === 'group' && $filters['group'] !== null;

        if ($applyGroup) {
            if ($filters['group'] === 'default') {
                $query->whereNull('group');
            } else {
                $query->where('group', $filters['group']);
            }
        }

        if ($filters['search'] !== '') {
            $like = '%'.$filters['search'].'%';
            $query->where(function (Builder $builder) use ($like): void {
                $builder
                    ->where('key', 'like', $like)
                    ->orWhere('group', 'like', $like)
                    ->orWhereHas('values', function (Builder $valueQuery) use ($like): void {
                        $valueQuery->where('value', 'like', $like);
                    });

                if (Str::contains($like, '.')) {
                    [$groupPart, $keyPart] = explode('.', trim($like, '%'), 2);

                    if ($groupPart !== '' && $keyPart !== '') {
                        $builder->orWhere(function (Builder $subQuery) use ($groupPart, $keyPart): void {
                            $subQuery
                                ->where('group', 'like', '%'.$groupPart.'%')
                                ->where('key', 'like', '%'.$keyPart.'%');
                        });
                    }
                }
            });
        }

        $this->applyStatusFilter($query, $filters['status'], $lastSyncAt, $locales);
        $this->applySort($query, $filters['sort'], $lastSyncAt);

        return $query
            ->paginate($perPage)
            ->withQueryString()
            ->through(function (VoxTranslation $translation) use ($locales, $lastSyncAt): array {
                $values = array_fill_keys($locales, '');
                $dynamicMatch = $this->dynamicKeys->match(
                    $translation->key,
                    $translation->group === 'json' ? null : $translation->group
                );

                foreach ($translation->values as $value) {
                    $values[$value->locale] = $value->value;
                }

                return [
                    'id' => $translation->id,
                    'group' => $translation->group,
                    'key' => $translation->key,
                    'display_key' => $this->displayKey($translation),
                    'status' => $translation->status,
                    'freshness_status' => $this->freshnessStatus($translation, $lastSyncAt),
                    'has_missing_values' => $this->hasMissingValues($translation, $locales),
                    'is_frontend' => $translation->is_frontend,
                    'is_orphan' => $translation->is_orphan,
                    'is_dynamic' => $dynamicMatch !== null,
                    'dynamic_pattern' => $dynamicMatch['pattern'] ?? null,
                    'source' => $translation->source,
                    'updated_at' => $translation->updated_at?->toIso8601String(),
                    'values' => $values,
                    'occurrences' => $translation->occurrences->map(function ($occurrence): array {
                        return [
                            'id' => $occurrence->id,
                            'file_path' => $occurrence->file_path,
                            'line_number' => $occurrence->line_number,
                            'context_before' => $occurrence->context_before,
                            'context_after' => $occurrence->context_after,
                        ];
                    })->all(),
                ];
            });
    }

    /**
     * @param  array<int, string>  $locales
     */
    private function applyStatusFilter(Builder $query, ?string $status, ?CarbonInterface $lastSyncAt, array $locales): void
    {
        if ($status === null) {
            return;
        }

        if ($status === 'orphan') {
            $query->where('is_orphan', true);

            return;
        }

        if ($status === 'dynamic') {
            $this->applyDynamicFilter($query);

            return;
        }

        $query->where('is_orphan', false);

        if ($status === 'missing') {
            $this->applyMissingFilter($query, $locales);

            return;
        }

        if ($lastSyncAt === null) {
            if (in_array($status, ['new', 'updated'], true)) {
                $query->whereRaw('1 = 0');

                return;
            }

            $query->where('status', $status);

            return;
        }

        if ($status === 'new') {
            $query->where('created_at', '>=', $lastSyncAt);

            return;
        }

        if ($status === 'updated') {
            $query
                ->where('created_at', '<', $lastSyncAt)
                ->where('updated_at', '>=', $lastSyncAt);

            return;
        }

        $query->where('status', $status);
    }

    private function applyDynamicFilter(Builder $query): void
    {
        $patterns = $this->dynamicKeys->patterns();

        if ($patterns === []) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where(function (Builder $dynamicQuery) use ($patterns): void {
            foreach ($patterns as $pattern) {
                $translationKey = TranslationKey::fromRaw($pattern);
                $fullKeyPattern = str_replace('*', '%', $pattern);
                $keyPattern = str_replace('*', '%', $translationKey->key);
                $groupPattern = $translationKey->group !== null
                    ? str_replace('*', '%', $translationKey->group)
                    : null;

                $dynamicQuery->orWhere(function (Builder $patternQuery) use (
                    $fullKeyPattern,
                    $groupPattern,
                    $keyPattern
                ): void {
                    if ($groupPattern !== null) {
                        $patternQuery
                            ->where(function (Builder $groupedTranslation) use ($groupPattern, $keyPattern): void {
                                $groupedTranslation
                                    ->where('group', 'like', $groupPattern)
                                    ->where('key', 'like', $keyPattern);
                            })
                            ->orWhere(function (Builder $jsonTranslation) use ($fullKeyPattern): void {
                                $jsonTranslation
                                    ->where(function (Builder $group): void {
                                        $group->whereNull('group')
                                            ->orWhere('group', 'json');
                                    })
                                    ->where('key', 'like', $fullKeyPattern);
                            });

                        return;
                    }

                    $patternQuery
                        ->where(function (Builder $group): void {
                            $group->whereNull('group')
                                ->orWhere('group', 'json');
                        })
                        ->where('key', 'like', $fullKeyPattern);
                });
            }
        });
    }

    /**
     * @param  array<int, string>  $locales
     */
    private function applyMissingFilter(Builder $query, array $locales): void
    {
        $flagPrefix = (string) config('vox.parse.missing_translation_prefix', '🚩');

        $query->where(function (Builder $builder) use ($locales, $flagPrefix): void {
            foreach ($locales as $locale) {
                $builder->orWhereDoesntHave('values', function (Builder $valueQuery) use ($locale): void {
                    $valueQuery->where('locale', $locale);
                });

                $builder->orWhereHas('values', function (Builder $valueQuery) use ($locale, $flagPrefix): void {
                    $valueQuery
                        ->where('locale', $locale)
                        ->where(function (Builder $q) use ($flagPrefix): void {
                            $q->where('value', '')
                                ->orWhereNull('value')
                                ->orWhere('value', 'like', $flagPrefix.'%');
                        });
                });
            }
        });
    }

    private function applySort(Builder $query, string $sort, ?CarbonInterface $lastSyncAt): void
    {
        if ($sort === 'status') {
            if ($lastSyncAt === null) {
                $query->orderByRaw("case when status = 'pending' then 0 when status = 'approved' then 1 else 2 end");
                $query->orderByDesc('updated_at');

                return;
            }

            $query->orderByRaw(
                "case
                    when created_at >= ? then 0
                    when updated_at >= ? and created_at < ? then 1
                    when status = 'pending' then 2
                    when status = 'approved' then 3
                    else 4
                end",
                [$lastSyncAt, $lastSyncAt, $lastSyncAt]
            );
            $query->orderByDesc('updated_at');

            return;
        }

        $query->orderByDesc('updated_at');
    }

    private function displayKey(VoxTranslation $translation): string
    {
        if ($translation->group === null || Str::startsWith($translation->group, 'json')) {
            return $translation->key;
        }

        return $translation->group.'.'.$translation->key;
    }

    private function freshnessStatus(VoxTranslation $translation, ?CarbonInterface $lastSyncAt): ?string
    {
        if ($lastSyncAt === null || $translation->is_orphan) {
            return null;
        }

        if ($translation->created_at?->greaterThanOrEqualTo($lastSyncAt)) {
            return 'new';
        }

        if ($translation->updated_at?->greaterThanOrEqualTo($lastSyncAt)) {
            return 'updated';
        }

        return null;
    }

    private function syncBoundary(?VoxAudit $audit): ?CarbonInterface
    {
        $startedAt = $audit?->context['started_at'] ?? null;

        if (is_string($startedAt) && $startedAt !== '') {
            return CarbonImmutable::parse($startedAt);
        }

        return $audit?->created_at;
    }

    /**
     * @param  array<int, string>  $locales
     */
    private function hasMissingValues(VoxTranslation $translation, array $locales): bool
    {
        $flagPrefix = (string) config('vox.parse.missing_translation_prefix', '🚩');
        $values = $translation->values->keyBy('locale');

        foreach ($locales as $locale) {
            $value = $values->get($locale)?->value;

            if (! is_string($value) || $value === '' || Str::startsWith($value, $flagPrefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    private function statusOptions(): array
    {
        return [
            ['value' => 'new', 'label' => 'New'],
            ['value' => 'updated', 'label' => 'Updated'],
            ['value' => 'pending', 'label' => 'Pending'],
            ['value' => 'approved', 'label' => 'Approved'],
            ['value' => 'missing', 'label' => 'Missing'],
            ['value' => 'orphan', 'label' => 'Orphan'],
            ['value' => 'dynamic', 'label' => 'Dynamic'],
        ];
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    private function sortOptions(): array
    {
        return [
            ['value' => 'updated_desc', 'label' => 'Updated (newest)'],
            ['value' => 'status', 'label' => 'Status'],
        ];
    }

    /**
     * @return array{available: bool, configured: bool, driver: string}
     */
    private function aiStatus(): array
    {
        $driver = (string) config('vox.translate.driver', 'openai');
        $available = $driver !== 'null';
        $configured = true;

        if ($driver === 'openai') {
            $apiKey = config('vox.translate.providers.openai.api_key');
            $configured = is_string($apiKey) && $apiKey !== '';
        }

        return [
            'available' => $available && $configured,
            'configured' => $configured,
            'driver' => $driver,
        ];
    }
}
