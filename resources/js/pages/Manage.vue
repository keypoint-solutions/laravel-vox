<script lang="ts" setup>
    import { Head, router, usePage } from '@inertiajs/vue3';
    import {
        Check,
        ChevronDown,
        ChevronLeft,
        ChevronRight,
        Code,
        FileText,
        GripVertical,
        Laptop,
        RotateCcw,
        Server,
        Sparkles,
    } from 'lucide-vue-next';
    import { computed, onMounted, onUnmounted, ref, watch } from 'vue';

    import type { SelectOption, ToggleOption } from '@/components/ui';
    import { Badge, Button, SearchInput, Select, SlidePanel, Textarea, ToggleGroup } from '@/components/ui';
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
        virtual_status: string;
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
        flash?: {
            translated_values?: Record<string, string>;
        };
    }

    const page = usePage<ManagePageProps>();

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
        router.get('/vox/manage', buildQuery(pageOverride), {
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
    }

    function closeEdit(): void {
        editTranslation.value = null;
        editValues.value = {};
        actionError.value = null;
    }

    function handleError(errors: Record<string, string>): void {
        actionError.value = Object.values(errors)[0] ?? 'Request failed.';
    }

    function refreshTranslations(): void {
        router.reload({ only: ['translations'] });
    }

    function toggleApproval(translation: TranslationItem): void {
        actionError.value = null;

        router.post(
            `/vox/manage/translations/${translation.id}/toggle-approval`,
            {},
            {
                preserveScroll: true,
                onError: handleError,
                onSuccess: refreshTranslations,
            }
        );
    }

    function saveEdit(): void {
        if (!editTranslation.value) {
            return;
        }

        actionError.value = null;
        isSaving.value = true;

        router.patch(
            `/vox/manage/translations/${editTranslation.value.id}`,
            { values: editValues.value },
            {
                preserveScroll: true,
                onFinish: () => {
                    isSaving.value = false;
                },
                onError: handleError,
                onSuccess: refreshTranslations,
            }
        );
    }

    function translateLocales(locales: string[]): void {
        if (!editTranslation.value) {
            return;
        }

        actionError.value = null;
        isTranslating.value = true;
        isTranslatingValues.value = true;

        router.post(
            `/vox/manage/translations/${editTranslation.value.id}/translate`,
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
                    const translatedValues = successPage.props.flash?.translated_values as
                        | Record<string, string>
                        | undefined;
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

    function formatTimestamp(value: string | null): string {
        if (!value) {
            return '—';
        }

        const date = new Date(value);
        if (Number.isNaN(date.getTime())) {
            return value;
        }

        return date.toLocaleString();
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
                    {{ lastSyncAt ? formatTimestamp(lastSyncAt) : 'Not synced yet' }}
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
                                    <Laptop
                                        v-if="group.has_frontend"
                                        class="size-3 shrink-0 text-emerald-500"
                                    />
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
                    <Button
                        class="shrink-0 sm:hidden"
                        size="icon"
                        title="Reset filters"
                        variant="ghost"
                        @click="clearFilters"
                    >
                        <RotateCcw class="size-4" />
                    </Button>

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
                        <Button
                            class="shrink-0"
                            size="icon"
                            title="Reset filters"
                            variant="ghost"
                            @click="clearFilters"
                        >
                            <RotateCcw class="size-4" />
                        </Button>
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
                                <Laptop
                                    v-if="group.has_frontend"
                                    class="size-3.5 shrink-0 text-emerald-500"
                                />
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
                                <Button
                                    class="shrink-0"
                                    size="icon"
                                    title="Reset filters"
                                    variant="ghost"
                                    @click="clearFilters"
                                >
                                    <RotateCcw class="size-4" />
                                </Button>
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
                <section class="bg-card overflow-hidden rounded-xl border">
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
                            class="group border-b last:border-b-0"
                        >
                            <!-- Row Header -->
                            <div
                                class="hover:bg-muted/30 flex cursor-pointer items-center gap-4 overflow-hidden px-4 py-3 transition-colors"
                                @click="openEdit(translation)"
                            >
                                <!-- Status Indicator -->
                                <div
                                    :class="[
                                        'size-2 shrink-0 rounded-full',
                                        translation.virtual_status === 'approved'
                                            ? 'bg-emerald-500'
                                            : translation.virtual_status === 'missing'
                                              ? 'bg-red-500'
                                              : translation.virtual_status === 'new'
                                                ? 'bg-blue-500'
                                                : translation.virtual_status === 'updated'
                                                  ? 'bg-amber-500'
                                                  : 'bg-muted-foreground/50',
                                    ]"
                                />

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
                                    </div>
                                    <p class="text-muted-foreground mt-0.5 line-clamp-1 text-xs">
                                        {{ translation.values?.[baseLocale] || '—' }}
                                    </p>
                                </div>

                                <!-- Meta -->
                                <div class="text-muted-foreground hidden shrink-0 items-center gap-3 text-xs sm:flex">
                                    <span class="flex items-center gap-1">
                                        <Laptop
                                            v-if="translation.is_frontend"
                                            class="size-3"
                                        />
                                        <Server
                                            v-else
                                            class="size-3"
                                        />
                                    </span>
                                    <span
                                        v-if="translation.occurrences.length"
                                        class="flex items-center gap-1"
                                    >
                                        <Code class="size-3" />
                                        {{ translation.occurrences.length }}
                                    </span>
                                </div>

                                <!-- Actions -->
                                <div class="flex shrink-0 items-center gap-1">
                                    <Button
                                        :class="translation.status === 'approved' ? 'text-emerald-500' : ''"
                                        class="size-8"
                                        size="icon"
                                        title="Toggle approval"
                                        variant="ghost"
                                        @click.stop="toggleApproval(translation)"
                                    >
                                        <Check class="size-4" />
                                    </Button>
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
                            <Button
                                :disabled="!hasPrev"
                                class="size-8"
                                size="icon"
                                variant="ghost"
                                @click="goToPage(pagination.current_page - 1)"
                            >
                                <ChevronLeft class="size-4" />
                            </Button>
                            <Button
                                :disabled="!hasNext"
                                class="size-8"
                                size="icon"
                                variant="ghost"
                                @click="goToPage(pagination.current_page + 1)"
                            >
                                <ChevronRight class="size-4" />
                            </Button>
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
                    <span class="text-muted-foreground"
                        >Updated {{ formatTimestamp(editTranslation?.updated_at) }}</span
                    >
                </div>
            </div>
        </template>

        <!-- Error Alert -->
        <div
            v-if="actionError"
            class="text-destructive border-destructive/40 bg-destructive/10 mb-4 rounded-lg border p-3 text-sm"
        >
            {{ actionError }}
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
                    :rows="2"
                    class="resize-none"
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
                    Save changes
                </Button>
            </div>
        </template>
    </SlidePanel>
</template>
