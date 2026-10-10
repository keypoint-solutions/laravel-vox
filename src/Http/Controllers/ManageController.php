<?php

namespace KeypointSolutions\LaravelVox\Http\Controllers;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Inertia\Inertia;
use Inertia\Response;
use KeypointSolutions\LaravelVox\Ai\AiAvailability;
use KeypointSolutions\LaravelVox\Http\Presenters\ManageTranslationPresenter;
use KeypointSolutions\LaravelVox\Http\Queries\ManageTranslationsQuery;
use KeypointSolutions\LaravelVox\Models\VoxAudit;
use KeypointSolutions\LaravelVox\Models\VoxTranslation;
use KeypointSolutions\LaravelVox\Support\VoxConfig;
use KeypointSolutions\LaravelVox\Translation\Locales\VoxLocaleResolver;
use KeypointSolutions\LaravelVox\Translation\Publishing\VoxFrontendManifest;
use KeypointSolutions\LaravelVox\Translation\Scanning\VoxDynamicKeyRegistry;
use KeypointSolutions\LaravelVox\Translation\TranslationFallbackRules;

class ManageController
{
    public function __construct(
        private VoxLocaleResolver $localeResolver,
        private VoxFrontendManifest $frontendManifest,
        private VoxDynamicKeyRegistry $dynamicKeys,
        private ManageTranslationsQuery $translations,
        private ManageTranslationPresenter $presenter,
        private AiAvailability $ai,
    ) {}

    public function __invoke(Request $request): Response
    {
        $filters = $this->resolveFilters($request);
        [$locales, $baseLocale] = $this->localeResolver->resolveLocalesWithBase();

        $lastSync = VoxAudit::query()
            ->whereIn('action', ['sync', 'sync-remote'])
            ->orderByDesc('created_at')
            ->first();
        $lastSyncBoundary = $this->syncBoundary($lastSync);

        $totalTranslations = VoxTranslation::query()->count();

        return Inertia::render('Manage', [
            'groups' => $this->loadGroups($filters['status'], $locales, $lastSyncBoundary),
            'translations' => $this->loadTranslations($request, $filters, $locales, $lastSyncBoundary),
            'locales' => $locales,
            'baseLocale' => $baseLocale,
            'fallbackRules' => app(TranslationFallbackRules::class)->all(),
            'missingTranslationPrefix' => VoxConfig::missingPrefix(),
            'filters' => $filters,
            'statusOptions' => $this->statusOptions(),
            'sortOptions' => $this->sortOptions(),
            'lastSyncAt' => $lastSync?->created_at?->toIso8601String(),
            'ai' => ['available' => $this->ai->available()],
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

        $allowedStatus = ['new', 'updated', 'pending', 'approved', 'missing', 'empty', 'orphan', 'dynamic', 'retained', 'pending-deletion', 'published-overrides', 'drafts'];
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
     * @param  array<int, string>  $locales
     * @return array<int, array{name: string, total: int, is_frontend_exported: bool, frontend_export_source: string|null, is_json: bool}>
     */
    private function loadGroups(?string $status, array $locales, ?CarbonInterface $lastSyncAt): array
    {
        $frontendGroups = $this->frontendManifest->groups();
        $frontendSource = $this->frontendManifest->usesConfiguredGroups() ? 'configured' : 'detected';
        $countQuery = VoxTranslation::query();
        $this->translations->applyStatusFilter($countQuery, $status, $lastSyncAt, $locales);
        $counts = $countQuery
            ->select('group')
            ->selectRaw('count(*) as total')
            ->groupBy('group')
            ->pluck('total', 'group');

        $groups = VoxTranslation::query()
            ->select('group')
            ->groupBy('group')
            ->orderBy('group')
            ->get();

        return $groups
            ->map(function (VoxTranslation $group) use ($frontendGroups, $frontendSource, $counts): array {
                $name = $group->group ?? 'default';
                $isJson = $name === 'json';
                $isFrontendExported = $isJson || in_array($name, $frontendGroups, true);

                return [
                    'name' => $name,
                    'total' => (int) $counts->get($group->group ?? '', 0),
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
        $perPage = $request->integer('per_page', 25);
        $perPage = in_array($perPage, [25, 50, 100], true) ? $perPage : 25;

        return $this->translations
            ->build($filters, $locales, $lastSyncAt)
            ->paginate($perPage)
            ->withQueryString()
            ->through(fn (VoxTranslation $translation): array => $this->presenter->present($translation, $locales, $lastSyncAt));
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
     * @return array<int, array{value: string, label: string}>
     */
    private function statusOptions(): array
    {
        return [
            ['value' => 'new', 'label' => 'New'],
            ['value' => 'updated', 'label' => 'Updated'],
            ['value' => 'published-overrides', 'label' => 'Published overrides'],
            ['value' => 'drafts', 'label' => 'Drafts'],
            ['value' => 'pending', 'label' => 'Pending review'],
            ['value' => 'approved', 'label' => 'Approved'],
            ['value' => 'missing', 'label' => 'Missing'],
            ['value' => 'empty', 'label' => 'Empty'],
            ['value' => 'orphan', 'label' => 'Orphan'],
            ['value' => 'dynamic', 'label' => 'Dynamic usage'],
            ['value' => 'retained', 'label' => 'Retained by rule'],
            ['value' => 'pending-deletion', 'label' => 'Pending deletion'],
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
}
