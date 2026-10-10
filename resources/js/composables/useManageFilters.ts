import { router, usePage } from '@inertiajs/vue3';
import { onUnmounted, ref, watch } from 'vue';

import type { ManagePageProps } from '@/types/manage';

/**
 * Holds the Manage page filter state and reloads the listing when it changes.
 */
export function useManageFilters(initialPerPage: number, onApply: () => void) {
    const page = usePage<ManagePageProps>();

    const perPage = ref(initialPerPage);
    const selectedGroup = ref<string | null>(page.props.filters?.group ?? null);
    const search = ref(page.props.filters?.search ?? '');
    const status = ref(page.props.filters?.status ?? '');
    const sort = ref(page.props.filters?.sort ?? 'updated_desc');
    const scope = ref(page.props.filters?.scope ?? (selectedGroup.value ? 'group' : 'all'));

    const isSyncingFilters = ref(false);
    let searchDebounceTimer: ReturnType<typeof setTimeout> | null = null;

    function buildQuery(pageOverride?: number): Record<string, string | number> {
        const query: Record<string, string | number> = { per_page: perPage.value };

        if (scope.value === 'group' && selectedGroup.value !== null) {
            query.group = selectedGroup.value;
        }

        if (search.value.trim() !== '') {
            query.search = search.value.trim();
        }

        if (status.value !== '') {
            query.status = status.value;
        }

        if (sort.value !== '') {
            query.sort = sort.value;
        }

        if (scope.value !== '') {
            query.scope = scope.value;
        }

        if (pageOverride && pageOverride > 1) {
            query.page = pageOverride;
        }

        return query;
    }

    function applyFilters(pageOverride = 1): void {
        onApply();
        router.get(page.props.vox?.routes?.manage ?? page.url.split('?')[0], buildQuery(pageOverride), {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            only: ['translations', 'filters', 'groups'],
        });
    }

    function applyFiltersDebounced(pageOverride = 1): void {
        if (searchDebounceTimer) {
            clearTimeout(searchDebounceTimer);
        }
        searchDebounceTimer = setTimeout(() => {
            applyFilters(pageOverride);
        }, 300);
    }

    function selectGroup(group: string | null): void {
        selectedGroup.value = group;
        scope.value = group === null ? 'all' : 'group';

        applyFilters(1);
    }

    function clearSearch(): void {
        search.value = '';
        applyFilters(1);
    }

    function clearFilters(): void {
        search.value = '';
        status.value = '';
        sort.value = 'updated_desc';
        scope.value = selectedGroup.value ? 'group' : 'all';
        applyFilters(1);
    }

    watch(
        () => page.props.filters,
        (filters) => {
            if (!filters) {
                return;
            }

            isSyncingFilters.value = true;
            selectedGroup.value = filters.group ?? null;
            search.value = filters.search ?? '';
            status.value = filters.status ?? '';
            sort.value = filters.sort ?? 'updated_desc';
            scope.value = filters.scope ?? (selectedGroup.value ? 'group' : 'all');
            // Reset the flag once Vue has processed the changes, so the search watcher skips this sync.
            setTimeout(() => {
                isSyncingFilters.value = false;
            }, 0);
        },
        { deep: true }
    );

    // Only reload when the user types, not while filters are being synced from the server.
    watch(search, () => {
        if (isSyncingFilters.value) {
            return;
        }
        applyFiltersDebounced(1);
    });

    onUnmounted(() => {
        if (searchDebounceTimer) {
            clearTimeout(searchDebounceTimer);
        }
    });

    return { perPage, selectedGroup, search, status, sort, applyFilters, selectGroup, clearSearch, clearFilters };
}
