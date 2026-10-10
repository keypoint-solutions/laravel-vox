<?php

namespace KeypointSolutions\LaravelVox\Http\Queries;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use KeypointSolutions\LaravelVox\Models\VoxTranslation;
use KeypointSolutions\LaravelVox\Support\VoxConfig;
use KeypointSolutions\LaravelVox\Translation\Locales\VoxLocaleResolver;
use KeypointSolutions\LaravelVox\Translation\Scanning\VoxDynamicKeyRegistry;
use KeypointSolutions\LaravelVox\Translation\TranslationFallbackRules;
use KeypointSolutions\LaravelVox\Translation\TranslationKey;

/**
 * Builds the Manage page listing: group scope, search, status filter and sort order.
 */
class ManageTranslationsQuery
{
    public function __construct(
        private VoxLocaleResolver $localeResolver,
        private VoxDynamicKeyRegistry $dynamicKeys,
    ) {}

    /**
     * @param  array{group: string|null, search: string, status: string|null, sort: string, scope: string}  $filters
     * @param  array<int, string>  $locales
     */
    public function build(array $filters, array $locales, ?CarbonInterface $lastSyncAt): Builder
    {
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

        return $query;
    }

    /**
     * @param  array<int, string>  $locales
     */
    public function applyStatusFilter(Builder $query, ?string $status, ?CarbonInterface $lastSyncAt, array $locales): void
    {
        $query->where('is_pending_delete', $status === 'pending-deletion');
        if ($status === 'pending-deletion') {
            return;
        }
        if ($status === null) {
            return;
        }

        if ($status === 'published-overrides') {
            $query->whereHas('values', fn (Builder $values) => $values->whereNotNull('published_override'));

            return;
        }

        if ($status === 'drafts') {
            $query->whereHas('values', fn (Builder $values) => $values->where('is_pending_publish', true)->where('is_approved', false));

            return;
        }

        if ($status === 'orphan') {
            $query->where('is_orphan', true);

            return;
        }

        if (in_array($status, ['dynamic', 'retained'], true)) {
            $this->applyDynamicFilter($query, $status);

            return;
        }

        $query->where('is_orphan', false);

        if (in_array($status, ['missing', 'empty'], true)) {
            $baseLocale = $this->localeResolver->resolveBaseLocale($locales);
            $method = $status === 'empty' ? 'whereDoesntHave' : 'whereHas';
            $query->{$method}('values', function (Builder $valueQuery) use ($baseLocale): void {
                $valueQuery->where('locale', $baseLocale)->whereNotNull('value')->where('value', '!=', '');
            });
            if ($status === 'empty') {
                return;
            }
        }

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

    private function applyDynamicFilter(Builder $query, string $status): void
    {
        $patterns = array_column(array_filter($this->dynamicKeys->entries(), fn (array $entry): bool => $status === 'dynamic'
            ? array_intersect(['detected', 'binding'], $entry['sources']) !== []
            : array_intersect(['settings', 'retained-config'], $entry['sources']) !== []), 'pattern');

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
        $flagPrefix = VoxConfig::missingPrefix();

        $query->where(function (Builder $builder) use ($locales, $flagPrefix): void {
            foreach ($locales as $locale) {
                $builder->orWhere(function (Builder $missing) use ($locale, $flagPrefix): void {
                    app(TranslationFallbackRules::class)->whereTranslated($missing, $locale);
                    $missing->where(function (Builder $builder) use ($locale, $flagPrefix): void {
                        $builder->whereDoesntHave('values', function (Builder $valueQuery) use ($locale): void {
                            $valueQuery->where('locale', $locale);
                        });

                        $builder->orWhereHas('values', function (Builder $valueQuery) use ($locale, $flagPrefix): void {
                            $valueQuery
                                ->where('locale', $locale)
                                ->where(function (Builder $q) use ($flagPrefix): void {
                                    $q->whereNull('value');

                                    if ($flagPrefix !== '') {
                                        $q->orWhere('value', 'like', $flagPrefix.'%');
                                    }
                                });
                        });
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
}
