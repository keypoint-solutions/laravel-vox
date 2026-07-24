<script lang="ts" setup>
    import { Head, router, usePage } from '@inertiajs/vue3';
    import {
        Check,
        ChevronDown,
        ChevronLeft,
        ChevronRight,
        CircleAlert,
        Code,
        FileText,
        GripVertical,
        Laptop,
        RotateCcw,
        Server,
        Sparkles,
    } from '@lucide/vue';
    import { computed, onMounted, onUnmounted, ref, watch } from 'vue';

    import type { SelectOption, ToggleOption } from '@/components/ui';
    import {
        Badge,
        Button,
        Checkbox,
        SearchInput,
        Select,
        SlidePanel,
        Textarea,
        ToggleGroup,
        Tooltip,
    } from '@/components/ui';
    import { useDateTime } from '@/composables/useDateTime';
    import Layout from '@/layouts/Layout.vue';
    import { cn } from '@/lib/utils';

    const LOCALE_ORDER_STORAGE_KEY = 'vox-locale-order';
    const LOCALE_LIST_HASH_KEY = 'vox-locale-list-hash';

    defineOptions({
        layout: Layout,
    });

    interface GroupItem {
        name: string;
        total: number;
        has_frontend: boolean;
        is_json: boolean;
    }

    interface OccurrenceItem {
        id: number;
        file_path: string;
        line_number: number | null;
        context_before: string | null;
        context_after: string | null;
    }

    interface TranslationItem {
        id: number;
        group: string | null;
        key: string;
        display_key: string;
        status: string;
        freshness_status: string | null;
        has_missing_values: boolean;
        is_frontend: boolean;
        source: string | null;
        updated_at: string | null;
        values: Record<string, string>;
        occurrences: OccurrenceItem[];
    }

    interface TranslationsPayload {
        data: TranslationItem[];
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
    }

    interface ManageFilters {
        group: string | null;
        search: string;
        status: string | null;
        sort: string;
        scope: string;
    }

    interface ManagePageProps {
        groups: GroupItem[];
        translations: TranslationsPayload;
        locales: string[];
        baseLocale: string;
        filters: ManageFilters;
        statusOptions: SelectOption[];
        sortOptions: SelectOption[];
        lastSyncAt: string | null;
        totalTranslations: number;
        ai: {
            available: boolean;
            configured: boolean;
            driver: string;
        };
    }

    const page = usePage<ManagePageProps>();
    const { formatDateTime } = useDateTime();

    const groups = computed(() => page.props.groups ?? []);
    const translations = computed(
        () => page.props.translations ?? { data: [], current_page: 1, last_page: 1, per_page: 25, total: 0 }
    );
    const locales = computed(() => page.props.locales ?? []);
    const baseLocale = computed(() => page.props.baseLocale ?? '');
    const sortOptions = computed<SelectOption[]>(() => page.props.sortOptions ?? []);
    const lastSyncAt = computed(() => page.props.lastSyncAt ?? null);
    const totalTranslations = computed(() => page.props.totalTranslations ?? 0);
    const aiStatus = computed(() => page.props.ai ?? { available: false, configured: false, driver: 'null' });
    const voxRoutes = computed(() => page.props.vox?.routes);

    const statusToggleOptions = computed<ToggleOption[]>(() => [
        { value: '', label: 'All' },
        { value: 'new', label: 'New' },
        { value: 'updated', label: 'Updated' },
        { value: 'missing', label: 'Missing' },
        { value: 'pending', label: 'Pending' },
        { value: 'approved', label: 'Approved' },
    ]);

    const groupSearch = ref('');
    const selectedGroup = ref<string | null>(page.props.filters?.group ?? null);
    const search = ref(page.props.filters?.search ?? '');
    const status = ref(page.props.filters?.status ?? '');
    const sort = ref(page.props.filters?.sort ?? 'updated_desc');
    const scope = ref(page.props.filters?.scope ?? (selectedGroup.value ? 'group' : 'all'));

    const editTranslation = ref<TranslationItem | null>(null);
    const editValues = ref<Record<string, string>>({});
    const isSaving = ref(false);
    const isTranslating = ref(false);
    const actionError = ref<string | null>(null);
    const actionSuccess = ref<string | null>(null);
    const pageActionSuccess = ref<string | null>(null);
    const selectedIds = ref<number[]>([]);
    const isBulkUpdating = ref(false);
    const showOccurrences = ref(false);
    const searchDebounceTimer = ref<ReturnType<typeof setTimeout> | null>(null);

    // Compact sticky header state
    const isCompactMode = ref(false);
    const showGroupsDropdown = ref(false);

    function handleScroll(): void {
        // Only enable compact mode if page is tall enough to avoid bouncing
        // Use different thresholds for enabling vs disabling (hysteresis) to prevent flickering
        const enableThreshold = 200;
        const disableThreshold = 150; // Lower threshold to disable, creates hysteresis
        const stickyHeaderHeight = 150; // Conservative estimate for 3-row mobile header
        const safetyBuffer = 100; // Extra buffer to prevent edge cases

        const minPageHeight = window.innerHeight + enableThreshold + stickyHeaderHeight + safetyBuffer;
        const pageIsTallEnough = document.documentElement.scrollHeight >= minPageHeight;

        if (!isCompactMode.value) {
            // Not in compact mode - check if we should enable it
            if (pageIsTallEnough && window.scrollY > enableThreshold) {
                isCompactMode.value = true;
            }
        } else {
            // In compact mode - check if we should disable it
            if (window.scrollY <= disableThreshold) {
                isCompactMode.value = false;
                showGroupsDropdown.value = false;
            }
        }
    }

    // Locale ordering state
    const localeOrder = ref<string[]>([]);
    const draggedLocale = ref<string | null>(null);
    const showLocaleOrder = ref(false);

    function computeLocaleListHash(localeList: string[]): string {
        return localeList.slice().sort().join(',');
    }

    function loadLocaleOrder(): void {
        const serverLocales = locales.value.filter((l) => l !== baseLocale.value);
        const currentHash = computeLocaleListHash(serverLocales);
        const storedHash = localStorage.getItem(LOCALE_LIST_HASH_KEY);

        if (storedHash !== currentHash) {
            // Server locales changed, reset order
            localeOrder.value = [...serverLocales];
            saveLocaleOrder();
            return;
        }

        const stored = localStorage.getItem(LOCALE_ORDER_STORAGE_KEY);
        if (stored) {
            try {
                const parsed = JSON.parse(stored) as string[];
                // Filter to only include locales that still exist (excluding base)
                const validOrder = parsed.filter((l) => serverLocales.includes(l));
                // Add any new locales that weren't in the stored order
                const newLocales = serverLocales.filter((l) => !validOrder.includes(l));
                localeOrder.value = [...validOrder, ...newLocales];
            } catch {
                localeOrder.value = [...serverLocales];
            }
        } else {
            localeOrder.value = [...serverLocales];
        }
    }

    function saveLocaleOrder(): void {
        const serverLocales = locales.value.filter((l) => l !== baseLocale.value);
        const hash = computeLocaleListHash(serverLocales);
        localStorage.setItem(LOCALE_ORDER_STORAGE_KEY, JSON.stringify(localeOrder.value));
        localStorage.setItem(LOCALE_LIST_HASH_KEY, hash);
    }

    function handleLocaleDragStart(locale: string): void {
        draggedLocale.value = locale;
    }

    function handleLocaleDragOver(event: DragEvent, targetLocale: string): void {
        event.preventDefault();
        if (!draggedLocale.value || draggedLocale.value === targetLocale) {
            return;
        }

        const fromIndex = localeOrder.value.indexOf(draggedLocale.value);
        const toIndex = localeOrder.value.indexOf(targetLocale);

        if (fromIndex === -1 || toIndex === -1) {
            return;
        }

        const newOrder = [...localeOrder.value];
        newOrder.splice(fromIndex, 1);
        newOrder.splice(toIndex, 0, draggedLocale.value);
        localeOrder.value = newOrder;
    }

    function handleLocaleDragEnd(): void {
        draggedLocale.value = null;
        saveLocaleOrder();
    }

    // Computed for ordered locales in the edit panel
    const orderedLocales = computed(() => {
        const base = baseLocale.value;
        const ordered = localeOrder.value.filter((l) => l !== base);
        return [base, ...ordered];
    });

    onMounted(() => {
        loadLocaleOrder();
        window.addEventListener('scroll', handleScroll, { passive: true });
    });

    onUnmounted(() => {
        window.removeEventListener('scroll', handleScroll);
    });

    // Watch for locale changes from server
    watch(
        () => locales.value,
        () => {
            loadLocaleOrder();
        }
    );

    const filteredGroups = computed(() => {
        const term = groupSearch.value.trim().toLowerCase();
        if (term === '') {
            return groups.value;
        }

        return groups.value.filter((group) => group.name.toLowerCase().includes(term));
    });

    const pagination = computed(() => ({
        current_page: translations.value.current_page ?? 1,
        last_page: translations.value.last_page ?? 1,
        per_page: translations.value.per_page ?? 25,
        total: translations.value.total ?? 0,
    }));
    const hasPrev = computed(() => pagination.value.current_page > 1);
    const hasNext = computed(() => pagination.value.current_page < pagination.value.last_page);
    const allVisibleSelected = computed(
        () =>
            translations.value.data.length > 0 &&
            translations.value.data.every((translation) => selectedIds.value.includes(translation.id))
    );

    const baseLocaleValue = computed(() => {
        if (!editTranslation.value) {
            return '';
        }

        return editValues.value[baseLocale.value] ?? '';
    });

    const targetLocales = computed(() => locales.value.filter((locale) => locale !== baseLocale.value));
    const canTranslate = computed(
        () => aiStatus.value.available && baseLocaleValue.value.trim() !== '' && targetLocales.value.length > 0
    );

    const isSyncingFilters = ref(false);
    const isTranslatingValues = ref(false);

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
            // Use nextTick to reset the flag after Vue has processed the changes
            setTimeout(() => {
                isSyncingFilters.value = false;
            }, 0);
        },
        { deep: true }
    );

    watch(
        () => translations.value.data,
        (items) => {
            // Don't overwrite values during AI translation
            if (!editTranslation.value || isTranslatingValues.value) {
                return;
            }

            const updated = items.find((item) => item.id === editTranslation.value?.id);
            if (!updated) {
                return;
            }

            editTranslation.value = updated;
            editValues.value = buildEditValues(updated);
        }
    );

    // Debounced search watcher - only trigger when user types, not during filter sync
    watch(search, () => {
        if (isSyncingFilters.value) {
            return;
        }
        applyFiltersDebounced(1);
    });

    function buildEditValues(translation: TranslationItem): Record<string, string> {
        const values: Record<string, string> = {};
        locales.value.forEach((locale) => {
            values[locale] = translation.values?.[locale] ?? '';
        });

        return values;
    }

    function buildQuery(pageOverride?: number): Record<string, string | number> {
        const query: Record<string, string | number> = {};

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
        selectedIds.value = [];
        router.get(voxRoutes.value?.manage ?? page.url.split('?')[0], buildQuery(pageOverride), {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            only: ['translations', 'filters'],
        });
    }

    function applyFiltersDebounced(pageOverride = 1): void {
        if (searchDebounceTimer.value) {
            clearTimeout(searchDebounceTimer.value);
        }
        searchDebounceTimer.value = setTimeout(() => {
            applyFilters(pageOverride);
        }, 300);
    }

    function selectGroup(group: string | null): void {
        selectedGroup.value = group;

        if (group === null) {
            scope.value = 'all';
        } else {
            scope.value = 'group';
        }

        applyFilters(1);
    }

    function clearFilters(): void {
        search.value = '';
        status.value = '';
        sort.value = 'updated_desc';
        scope.value = selectedGroup.value ? 'group' : 'all';
        applyFilters(1);
    }

    function goToPage(pageNumber: number): void {
        applyFilters(pageNumber);
    }

    function openEdit(translation: TranslationItem): void {
        showOccurrences.value = false;
        editTranslation.value = translation;
        editValues.value = buildEditValues(translation);
        actionError.value = null;
        actionSuccess.value = null;
    }

    function closeEdit(): void {
        editTranslation.value = null;
        editValues.value = {};
        actionError.value = null;
        actionSuccess.value = null;
    }

    function handleError(errors: Record<string, string>): void {
        actionSuccess.value = null;
        actionError.value = Object.values(errors)[0] ?? 'Request failed.';
    }

    function workflowStatusLabel(translation: TranslationItem): string {
        return translation.status === 'approved' ? 'Approved' : 'Pending review';
    }

    function approvalActionLabel(translation: TranslationItem): string {
        return translation.status === 'approved' ? 'Return to review' : 'Approve translation';
    }

    function setSelected(translationId: number, selected: boolean): void {
        selectedIds.value = selected
            ? Array.from(new Set([...selectedIds.value, translationId]))
            : selectedIds.value.filter((id) => id !== translationId);
    }

    function selectAllVisible(selected: boolean): void {
        const visibleIds = translations.value.data.map((translation) => translation.id);

        if (selected) {
            selectedIds.value = Array.from(new Set([...selectedIds.value, ...visibleIds]));

            return;
        }

        selectedIds.value = selectedIds.value.filter((id) => !visibleIds.includes(id));
    }

    function bulkApproval(targetStatus: 'pending' | 'approved'): void {
        if (selectedIds.value.length === 0) {
            return;
        }

        isBulkUpdating.value = true;
        pageActionSuccess.value = null;
        actionError.value = null;

        router.post(
            voxRoutes.value?.manage_translation_bulk_approval ?? '',
            {
                ids: selectedIds.value,
                status: targetStatus,
            },
            {
                preserveScroll: true,
                onError: handleError,
                onSuccess: (successPage) => {
                    pageActionSuccess.value =
                        (successPage.flash?.success as string | undefined) ?? 'Translation statuses updated.';
                    selectedIds.value = [];
                },
                onFinish: () => {
                    isBulkUpdating.value = false;
                },
            }
        );
    }

    function translationActionUrl(route: string | undefined, translationId: number): string {
        return route?.replace('__translation__', String(translationId)) ?? '';
    }

    function toggleApproval(translation: TranslationItem): void {
        actionError.value = null;
        pageActionSuccess.value = null;

        router.post(
            translationActionUrl(voxRoutes.value?.manage_translation_toggle_approval, translation.id),
            {},
            {
                preserveScroll: true,
                onError: handleError,
                onSuccess: (successPage) => {
                    pageActionSuccess.value =
                        (successPage.flash?.success as string | undefined) ?? 'Translation status updated.';
                },
            }
        );
    }

    function saveEdit(): void {
        if (!editTranslation.value) {
            return;
        }

        actionError.value = null;
        actionSuccess.value = null;
        isSaving.value = true;

        router.patch(
            translationActionUrl(voxRoutes.value?.manage_translation_update, editTranslation.value.id),
            { values: editValues.value },
            {
                preserveScroll: true,
                onFinish: () => {
                    isSaving.value = false;
                },
                onError: handleError,
                onSuccess: (successPage) => {
                    actionSuccess.value = (successPage.flash?.success as string | undefined) ?? 'Translations saved.';
                },
            }
        );
    }

    function translateLocales(locales: string[]): void {
        if (!editTranslation.value) {
            return;
        }

        actionError.value = null;
        actionSuccess.value = null;
        isTranslating.value = true;
        isTranslatingValues.value = true;

        router.post(
            translationActionUrl(voxRoutes.value?.manage_translation_translate, editTranslation.value.id),
            {
                locales,
                base_value: editValues.value[baseLocale.value] ?? '',
            },
            {
                preserveScroll: true,
                preserveState: true,
                onFinish: () => {
                    isTranslating.value = false;
                    // Reset the flag after a short delay to allow the watcher to skip
                    setTimeout(() => {
                        isTranslatingValues.value = false;
                    }, 100);
                },
                onError: handleError,
                onSuccess: (successPage) => {
                    const translatedValues = successPage.flash?.translated_values as Record<string, string> | undefined;
                    if (translatedValues) {
                        Object.entries(translatedValues).forEach(([locale, value]) => {
                            editValues.value[locale] = value;
                        });
                    }
                },
            }
        );
    }

    function translateLocale(locale: string): void {
        translateLocales([locale]);
    }

    function translateAll(): void {
        translateLocales(targetLocales.value);
    }
</script>

<template>
    <Head title="Manage" />

    <div :class="['space-y-4', isCompactMode ? 'pt-32 sm:pt-14' : '']">
        <!-- Page Header -->
        <div>
            <h1 class="text-2xl font-semibold tracking-tight">Manage Translations</h1>
            <p class="text-muted-foreground mt-1 text-sm">Browse, edit, and approve translations across all locales.</p>
        </div>

        <div
            v-if="pageActionSuccess"
            role="status"
            aria-live="polite"
            class="flex items-center gap-2 rounded-lg border border-emerald-500/30 bg-emerald-500/10 p-3 text-sm text-emerald-600"
        >
            <Check class="size-4 shrink-0" />
            {{ pageActionSuccess }}
        </div>

        <!-- Stats Cards -->
        <div class="grid gap-4 sm:grid-cols-3">
            <div class="bg-card rounded-xl border p-4">
                <p class="text-muted-foreground text-xs font-medium tracking-wide uppercase">Groups</p>
                <p class="mt-1 text-2xl font-semibold tabular-nums">{{ groups.length }}</p>
            </div>
            <div class="bg-card rounded-xl border p-4">
                <p class="text-muted-foreground text-xs font-medium tracking-wide uppercase">Translations</p>
                <p class="mt-1 text-2xl font-semibold tabular-nums">{{ totalTranslations }}</p>
            </div>
            <div class="bg-card rounded-xl border p-4">
                <p class="text-muted-foreground text-xs font-medium tracking-wide uppercase">Last Sync</p>
                <p class="mt-1 text-sm font-medium">
                    {{ formatDateTime(lastSyncAt, 'Not synced yet') }}
                </p>
            </div>
        </div>

        <!-- Compact Sticky Header (appears on scroll) -->
        <div
            v-if="isCompactMode"
            class="bg-background/95 supports-backdrop-filter:bg-background/60 fixed inset-x-0 top-0 z-50 border-b backdrop-blur"
        >
            <div class="mx-auto max-w-7xl space-y-2 px-4 py-2 sm:space-y-0 sm:px-4 lg:px-8">
                <!-- Row 1: Groups + Search + Reset -->
                <div class="flex items-center gap-2 sm:gap-3">
                    <!-- Groups Dropdown -->
                    <div class="relative">
                        <button
                            class="bg-card hover:bg-muted flex items-center gap-2 rounded-lg border px-3 py-1.5 text-sm font-medium transition-colors"
                            type="button"
                            @click="showGroupsDropdown = !showGroupsDropdown"
                        >
                            <span class="max-w-25 truncate sm:max-w-30">{{ selectedGroup ?? 'All groups' }}</span>
                            <ChevronDown class="size-4 shrink-0 opacity-50" />
                        </button>
                        <div
                            v-if="showGroupsDropdown"
                            class="bg-card absolute top-full left-0 z-50 mt-1 max-h-64 w-56 overflow-y-auto rounded-lg border shadow-lg"
                        >
                            <button
                                :class="
                                    cn(
                                        'flex w-full items-center justify-between px-3 py-2 text-sm transition-colors',
                                        selectedGroup === null
                                            ? 'bg-primary/10 text-primary font-medium'
                                            : 'hover:bg-muted'
                                    )
                                "
                                type="button"
                                @click="
                                    selectGroup(null);
                                    showGroupsDropdown = false;
                                "
                            >
                                <span>All groups</span>
                                <span class="text-muted-foreground text-xs tabular-nums">{{ totalTranslations }}</span>
                            </button>
                            <button
                                v-for="group in groups"
                                :key="group.name"
                                :class="
                                    cn(
                                        'flex w-full items-center justify-between px-3 py-2 text-sm transition-colors',
                                        selectedGroup === group.name
                                            ? 'bg-primary/10 text-primary font-medium'
                                            : 'hover:bg-muted'
                                    )
                                "
                                type="button"
                                @click="
                                    selectGroup(group.name);
                                    showGroupsDropdown = false;
                                "
                            >
                                <span class="flex min-w-0 items-center gap-1.5">
                                    <Tooltip
                                        v-if="group.has_frontend"
                                        text="Contains translations used by frontend code"
                                    >
                                        <Laptop class="size-3 shrink-0 text-emerald-500" />
                                    </Tooltip>
                                    <span class="truncate">{{ group.name }}</span>
                                </span>
                                <span class="text-muted-foreground text-xs tabular-nums">{{ group.total }}</span>
                            </button>
                        </div>
                    </div>

                    <!-- Search -->
                    <SearchInput
                        v-model="search"
                        class="min-w-0 flex-1 sm:max-w-xs"
                        placeholder="Search..."
                        @clear="
                            () => {
                                search = '';
                                applyFilters(1);
                            }
                        "
                    />

                    <!-- Reset (visible on mobile, hidden on desktop where it's in row with filters) -->
                    <span class="sm:hidden">
                        <Tooltip text="Reset all filters">
                            <Button
                                aria-label="Reset all filters"
                                class="shrink-0"
                                size="icon"
                                variant="ghost"
                                @click="clearFilters"
                            >
                                <RotateCcw class="size-4" />
                            </Button>
                        </Tooltip>
                    </span>

                    <!-- Desktop: Status Toggle + Sort + Reset -->
                    <div class="hidden items-center gap-4 sm:flex">
                        <ToggleGroup
                            v-model="status"
                            :options="statusToggleOptions"
                            size="sm"
                            @update:model-value="applyFilters(1)"
                        />
                        <Select
                            v-model="sort"
                            :options="sortOptions"
                            class="w-36"
                            @update:model-value="applyFilters(1)"
                        />
                        <Tooltip text="Reset all filters">
                            <Button
                                aria-label="Reset all filters"
                                class="shrink-0"
                                size="icon"
                                variant="ghost"
                                @click="clearFilters"
                            >
                                <RotateCcw class="size-4" />
                            </Button>
                        </Tooltip>
                    </div>
                </div>

                <!-- Row 2: Status Toggle (mobile only) -->
                <div class="sm:hidden">
                    <ToggleGroup
                        v-model="status"
                        :options="statusToggleOptions"
                        size="sm"
                        @update:model-value="applyFilters(1)"
                    />
                </div>

                <!-- Row 3: Sort (mobile only) -->
                <div class="flex items-center gap-2 sm:hidden">
                    <Select
                        v-model="sort"
                        :options="sortOptions"
                        class="flex-1"
                        @update:model-value="applyFilters(1)"
                    />
                </div>
            </div>
        </div>

        <!-- Click outside to close groups dropdown -->
        <div
            v-if="showGroupsDropdown"
            class="fixed inset-0 z-40"
            @click="showGroupsDropdown = false"
        />

        <!-- Main Content -->
        <div :class="['grid gap-4', isCompactMode ? '' : 'lg:grid-cols-[240px,1fr]']">
            <!-- Sidebar: Groups (hidden in compact mode) -->
            <aside
                v-if="!isCompactMode"
                class="space-y-4"
            >
                <section class="bg-card rounded-xl border p-4">
                    <div class="flex items-center justify-between">
                        <h2 class="text-sm font-semibold">Groups</h2>
                        <span class="text-muted-foreground text-xs">{{ groups.length }}</span>
                    </div>
                    <SearchInput
                        v-model="groupSearch"
                        class="mt-3"
                        placeholder="Filter groups..."
                        @clear="groupSearch = ''"
                    />
                    <div class="mt-3 max-h-80 space-y-0.5 overflow-y-auto">
                        <button
                            :class="
                                cn(
                                    'flex w-full items-center justify-between rounded-lg px-3 py-2 text-sm transition-colors',
                                    selectedGroup === null
                                        ? 'bg-primary/10 text-primary font-medium'
                                        : 'text-muted-foreground hover:bg-muted hover:text-foreground'
                                )
                            "
                            type="button"
                            @click="selectGroup(null)"
                        >
                            <span>All groups</span>
                            <span class="text-xs tabular-nums">{{ pagination.total }}</span>
                        </button>
                        <button
                            v-for="group in filteredGroups"
                            :key="group.name"
                            :class="
                                cn(
                                    'flex w-full items-center justify-between rounded-lg px-3 py-2 text-sm transition-colors',
                                    selectedGroup === group.name
                                        ? 'bg-primary/10 text-primary font-medium'
                                        : 'text-muted-foreground hover:bg-muted hover:text-foreground'
                                )
                            "
                            type="button"
                            @click="selectGroup(group.name)"
                        >
                            <span class="flex min-w-0 items-center gap-1.5">
                                <Tooltip
                                    v-if="group.has_frontend"
                                    text="Contains translations used by frontend code"
                                >
                                    <Laptop class="size-3.5 shrink-0 text-emerald-500" />
                                </Tooltip>
                                <span class="truncate">{{ group.name }}</span>
                                <Badge
                                    v-if="group.is_json"
                                    class="shrink-0"
                                    variant="secondary"
                                >
                                    JSON
                                </Badge>
                            </span>
                            <span class="text-xs tabular-nums">{{ group.total }}</span>
                        </button>
                    </div>
                </section>
            </aside>

            <!-- Main: Translations List -->
            <div class="min-w-0 space-y-4">
                <!-- Filters (hidden in compact mode - shown in sticky header instead) -->
                <section
                    v-if="!isCompactMode"
                    class="bg-card rounded-xl border p-4"
                >
                    <div class="flex flex-col gap-4">
                        <!-- Search Row -->
                        <div class="flex flex-col gap-4 sm:flex-row sm:items-center">
                            <SearchInput
                                v-model="search"
                                class="flex-1"
                                placeholder="Search by key, group, or value..."
                                @clear="
                                    () => {
                                        search = '';
                                        applyFilters(1);
                                    }
                                "
                            />
                            <div class="flex items-center gap-2">
                                <Select
                                    v-model="sort"
                                    :options="sortOptions"
                                    class="w-40"
                                    @update:model-value="applyFilters(1)"
                                />
                                <Tooltip text="Reset all filters">
                                    <Button
                                        aria-label="Reset all filters"
                                        class="shrink-0"
                                        size="icon"
                                        variant="ghost"
                                        @click="clearFilters"
                                    >
                                        <RotateCcw class="size-4" />
                                    </Button>
                                </Tooltip>
                            </div>
                        </div>

                        <!-- Status Toggle -->
                        <div class="flex flex-wrap items-center gap-4">
                            <ToggleGroup
                                v-model="status"
                                :options="statusToggleOptions"
                                size="sm"
                                @update:model-value="applyFilters(1)"
                            />
                        </div>
                    </div>
                </section>

                <!-- Translations List -->
                <section class="bg-card rounded-xl border">
                    <div
                        v-if="translations.data.length > 0"
                        class="bg-muted/20 flex flex-col gap-3 border-b px-4 py-3 sm:flex-row sm:items-center sm:justify-between"
                    >
                        <div class="flex items-center gap-2">
                            <span @click.stop>
                                <Checkbox
                                    aria-label="Select all visible translations"
                                    :model-value="allVisibleSelected"
                                    @update:model-value="selectAllVisible"
                                />
                            </span>
                            <span class="text-muted-foreground text-xs">
                                {{
                                    selectedIds.length > 0
                                        ? `${selectedIds.length} selected`
                                        : 'Select all visible translations'
                                }}
                            </span>
                        </div>
                        <div
                            v-if="selectedIds.length > 0"
                            class="flex items-center gap-2"
                        >
                            <Button
                                :disabled="isBulkUpdating"
                                size="sm"
                                variant="outline"
                                @click="bulkApproval('pending')"
                            >
                                Return to review
                            </Button>
                            <Button
                                :disabled="isBulkUpdating"
                                size="sm"
                                @click="bulkApproval('approved')"
                            >
                                <Check class="size-4" />
                                Approve
                            </Button>
                        </div>
                    </div>

                    <!-- Empty State -->
                    <div
                        v-if="translations.data.length === 0"
                        class="flex flex-col items-center justify-center py-16 text-center"
                    >
                        <FileText class="text-muted-foreground/50 size-12" />
                        <p class="text-muted-foreground mt-4 text-sm">No translations match the current filters.</p>
                        <Button
                            class="mt-4"
                            size="sm"
                            variant="outline"
                            @click="clearFilters"
                        >
                            Clear filters
                        </Button>
                    </div>

                    <!-- Compact List -->
                    <div v-else>
                        <div
                            v-for="translation in translations.data"
                            :key="translation.id"
                            class="border-b last:border-b-0"
                        >
                            <!-- Row Header -->
                            <div
                                class="hover:bg-muted/30 flex cursor-pointer items-center gap-3 px-4 py-3 transition-colors"
                                :data-test="`translation-row-${translation.id}`"
                                @click="openEdit(translation)"
                            >
                                <span @click.stop>
                                    <Checkbox
                                        :aria-label="`Select ${translation.display_key}`"
                                        :model-value="selectedIds.includes(translation.id)"
                                        @update:model-value="setSelected(translation.id, $event)"
                                    />
                                </span>

                                <!-- Status Indicator -->
                                <Tooltip :text="workflowStatusLabel(translation)">
                                    <span
                                        :aria-label="workflowStatusLabel(translation)"
                                        data-test="workflow-status"
                                        :class="[
                                            'size-2 shrink-0 rounded-full',
                                            translation.status === 'approved' ? 'bg-emerald-500' : 'bg-amber-500',
                                        ]"
                                    />
                                </Tooltip>

                                <!-- Key & Group -->
                                <div class="min-w-0 flex-1 overflow-hidden">
                                    <div class="flex min-w-0 items-center gap-2">
                                        <span class="min-w-0 truncate text-sm font-medium">{{
                                            translation.display_key
                                        }}</span>
                                        <Badge
                                            class="shrink-0"
                                            variant="secondary"
                                        >
                                            {{ translation.group ?? 'default' }}
                                        </Badge>
                                        <Badge
                                            v-if="translation.freshness_status"
                                            class="shrink-0 capitalize"
                                            variant="outline"
                                        >
                                            {{ translation.freshness_status }}
                                        </Badge>
                                    </div>
                                    <p class="text-muted-foreground mt-0.5 line-clamp-1 text-xs">
                                        {{ translation.values?.[baseLocale] || '—' }}
                                    </p>
                                </div>

                                <!-- Meta -->
                                <div class="text-muted-foreground hidden shrink-0 items-center gap-3 text-xs sm:flex">
                                    <Tooltip
                                        :text="
                                            translation.is_frontend ? 'Used in frontend code' : 'Used in backend code'
                                        "
                                    >
                                        <span
                                            :aria-label="
                                                translation.is_frontend
                                                    ? 'Used in frontend code'
                                                    : 'Used in backend code'
                                            "
                                            class="flex items-center"
                                        >
                                            <Laptop
                                                v-if="translation.is_frontend"
                                                class="size-3"
                                            />
                                            <Server
                                                v-else
                                                class="size-3"
                                            />
                                        </span>
                                    </Tooltip>
                                    <Tooltip
                                        v-if="translation.occurrences.length"
                                        :text="`${translation.occurrences.length} code occurrence${translation.occurrences.length === 1 ? '' : 's'}`"
                                    >
                                        <span class="flex items-center gap-1">
                                            <Code class="size-3" />
                                            {{ translation.occurrences.length }}
                                        </span>
                                    </Tooltip>
                                    <Tooltip
                                        v-if="translation.has_missing_values"
                                        text="One or more locale values are missing"
                                    >
                                        <CircleAlert
                                            aria-label="One or more locale values are missing"
                                            class="size-3 text-red-500"
                                        />
                                    </Tooltip>
                                </div>

                                <!-- Actions -->
                                <div class="flex shrink-0 items-center gap-1">
                                    <Tooltip :text="approvalActionLabel(translation)">
                                        <Button
                                            :aria-label="approvalActionLabel(translation)"
                                            data-test="approval-action"
                                            :class="translation.status === 'approved' ? 'text-emerald-500' : ''"
                                            class="size-8"
                                            size="icon"
                                            variant="ghost"
                                            @click.stop="toggleApproval(translation)"
                                        >
                                            <Check class="size-4" />
                                        </Button>
                                    </Tooltip>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Pagination -->
                    <div
                        v-if="translations.data.length > 0"
                        class="bg-muted/30 flex items-center justify-between border-t px-4 py-3"
                    >
                        <p class="text-muted-foreground text-xs">
                            Page {{ pagination.current_page }} of {{ pagination.last_page }}
                            <span class="hidden sm:inline">• {{ pagination.total }} items</span>
                        </p>
                        <div class="flex items-center gap-1">
                            <Tooltip text="Previous page">
                                <Button
                                    aria-label="Previous page"
                                    :disabled="!hasPrev"
                                    class="size-8"
                                    size="icon"
                                    variant="ghost"
                                    @click="goToPage(pagination.current_page - 1)"
                                >
                                    <ChevronLeft class="size-4" />
                                </Button>
                            </Tooltip>
                            <Tooltip text="Next page">
                                <Button
                                    aria-label="Next page"
                                    :disabled="!hasNext"
                                    class="size-8"
                                    size="icon"
                                    variant="ghost"
                                    @click="goToPage(pagination.current_page + 1)"
                                >
                                    <ChevronRight class="size-4" />
                                </Button>
                            </Tooltip>
                        </div>
                    </div>
                </section>
            </div>
        </div>
    </div>

    <!-- Edit Panel -->
    <SlidePanel
        :open="!!editTranslation"
        :subtitle="editTranslation?.group ?? 'default'"
        :title="editTranslation?.display_key"
        @close="closeEdit"
    >
        <template #header>
            <div class="min-w-0 flex-1">
                <p class="text-muted-foreground text-xs tracking-[0.2em] uppercase">Editing</p>
                <h2 class="mt-1 truncate text-lg font-semibold">{{ editTranslation?.display_key }}</h2>
                <div class="mt-1 flex flex-wrap items-center gap-2 text-xs">
                    <Badge variant="secondary">{{ editTranslation?.group ?? 'default' }}</Badge>
                    <span class="text-muted-foreground">Updated {{ formatDateTime(editTranslation?.updated_at) }}</span>
                </div>
            </div>
        </template>

        <!-- Error Alert -->
        <div
            v-if="actionError"
            role="alert"
            class="text-destructive border-destructive/40 bg-destructive/10 mb-4 rounded-lg border p-3 text-sm"
        >
            {{ actionError }}
        </div>

        <div
            v-if="actionSuccess"
            role="status"
            aria-live="polite"
            class="mb-4 flex items-center gap-2 rounded-lg border border-emerald-500/30 bg-emerald-500/10 p-3 text-sm text-emerald-600"
        >
            <Check class="size-4 shrink-0" />
            {{ actionSuccess }}
        </div>

        <!-- Locale Order Control -->
        <div
            v-if="localeOrder.length > 1"
            class="mb-4"
        >
            <button
                class="text-muted-foreground hover:text-foreground flex items-center gap-1 text-xs transition-colors"
                type="button"
                @click="showLocaleOrder = !showLocaleOrder"
            >
                <GripVertical class="size-3" />
                {{ showLocaleOrder ? 'Hide' : 'Reorder locales' }}
            </button>
            <div
                v-if="showLocaleOrder"
                class="bg-muted/30 mt-2 rounded-lg border p-2"
            >
                <p class="text-muted-foreground mb-2 text-xs">Drag to reorder (base locale always first):</p>
                <div class="space-y-1">
                    <div
                        v-for="locale in localeOrder"
                        :key="locale"
                        :class="[
                            'flex cursor-grab items-center gap-2 rounded px-2 py-1.5 text-xs transition-colors',
                            draggedLocale === locale ? 'bg-primary/20' : 'hover:bg-muted',
                        ]"
                        draggable="true"
                        @dragend="handleLocaleDragEnd"
                        @dragover="handleLocaleDragOver($event, locale)"
                        @dragstart="handleLocaleDragStart(locale)"
                    >
                        <GripVertical class="text-muted-foreground size-3" />
                        <span class="font-medium uppercase">{{ locale }}</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Locale Values -->
        <div class="space-y-4">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm font-semibold">Translations</p>
                    <p class="text-muted-foreground text-xs">Edit values for each locale.</p>
                </div>
                <Button
                    v-if="aiStatus.available"
                    :disabled="!canTranslate || isTranslating"
                    size="sm"
                    variant="outline"
                    @click="translateAll"
                >
                    <Sparkles class="size-4" />
                    Translate all
                </Button>
            </div>

            <div
                v-for="locale in orderedLocales"
                :key="locale"
                class="space-y-1.5"
            >
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <span class="text-muted-foreground text-xs font-medium uppercase">{{ locale }}</span>
                        <Badge
                            v-if="locale === baseLocale"
                            variant="outline"
                        >
                            Base
                        </Badge>
                    </div>
                    <Button
                        v-if="aiStatus.available && locale !== baseLocale"
                        :disabled="!canTranslate || isTranslating"
                        class="h-7 px-2"
                        size="sm"
                        variant="ghost"
                        @click="translateLocale(locale)"
                    >
                        <Sparkles class="size-3" />
                        AI
                    </Button>
                </div>
                <Textarea
                    v-model="editValues[locale]"
                    :data-test="`translation-value-${locale}`"
                    :rows="2"
                    class="resize-none"
                    @update:model-value="actionSuccess = null"
                />
            </div>
        </div>

        <!-- Occurrences Section -->
        <div
            v-if="editTranslation?.occurrences?.length"
            class="mt-4 border-t pt-4"
        >
            <button
                class="flex w-full cursor-pointer items-center justify-between text-sm font-semibold"
                type="button"
                @click="showOccurrences = !showOccurrences"
            >
                <span class="flex items-center gap-2">
                    <FileText class="size-4" />
                    Occurrences ({{ editTranslation.occurrences.length }})
                </span>
                <ChevronRight :class="['size-4 transition-transform', showOccurrences && 'rotate-90']" />
            </button>
            <div
                v-if="showOccurrences"
                class="mt-3 space-y-2"
            >
                <div
                    v-for="occurrence in editTranslation.occurrences"
                    :key="occurrence.id"
                    class="bg-muted/40 rounded-lg border p-3"
                >
                    <div class="text-muted-foreground flex items-center gap-1 text-xs">
                        <Code class="size-3" />
                        <span class="truncate">{{ occurrence.file_path }}</span>
                        <span v-if="occurrence.line_number">:{{ occurrence.line_number }}</span>
                    </div>
                    <div
                        v-if="occurrence.context_before || occurrence.context_after"
                        class="mt-2 space-y-1 font-mono text-xs"
                    >
                        <p class="text-muted-foreground">
                            <template v-if="occurrence.context_before">{{ occurrence.context_before }}</template
                            >KEY<template v-if="occurrence.context_after">{{ occurrence.context_after }}</template>
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <template #footer>
            <div class="flex items-center justify-end gap-2">
                <Button
                    :disabled="isSaving"
                    variant="outline"
                    @click="closeEdit"
                >
                    Cancel
                </Button>
                <Button
                    :disabled="isSaving"
                    @click="saveEdit"
                >
                    {{ isSaving ? 'Saving…' : 'Save changes' }}
                </Button>
            </div>
        </template>
    </SlidePanel>
</template>
