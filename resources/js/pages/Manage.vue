<script lang="ts" setup>
    import { Head, router, usePage } from '@inertiajs/vue3';
    import {
        Braces,
        Check,
        ChevronDown,
        ChevronLeft,
        ChevronRight,
        CircleAlert,
        Code,
        Copy,
        FileText,
        Laptop,
        Plus,
        RotateCcw,
        Server,
        Sparkles,
        Trash2,
        X,
    } from '@lucide/vue';
    import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue';

    import type { SelectOption, ToggleOption } from '@/components/ui';
    import {
        Badge,
        Button,
        Checkbox,
        FormField,
        Input,
        SearchInput,
        Select,
        SlidePanel,
        Textarea,
        ToggleGroup,
        Tooltip,
    } from '@/components/ui';
    import PageSizeSelect from '@/components/ui/PageSizeSelect.vue';
    import { useDateTime } from '@/composables/useDateTime';
    import Layout from '@/layouts/Layout.vue';
    import { copyTextToClipboard } from '@/lib/clipboard';
    import { cn } from '@/lib/utils';

    defineOptions({
        layout: Layout,
    });

    interface GroupItem {
        name: string;
        total: number;
        is_frontend_exported: boolean;
        frontend_export_source: 'configured' | 'detected' | 'json' | null;
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
        is_orphan: boolean;
        is_dynamic: boolean;
        is_retained: boolean;
        is_ignored: boolean;
        is_pending_delete: boolean;
        deletion_unavailable_reason: string | null;
        matching_patterns: string[];
        retention_sources: string[];
        dynamic_occurrences: { file: string; line: number | null; context: string | null }[];
        dynamic_pattern: string | null;
        source: string | null;
        updated_at: string | null;
        values_count: number;
        values: Record<string, string>;
        published_overrides: Record<string, string | null>;
        file_values: Record<string, string | null>;
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
        dynamicPatterns: {
            pattern: string;
            is_frontend: boolean;
            sources: string[];
        }[];
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
    const dynamicPatterns = computed(() => page.props.dynamicPatterns ?? []);
    const dynamicPatternOptions = computed<SelectOption[]>(() =>
        dynamicPatterns.value.map((entry) => ({
            value: entry.pattern,
            label: `${entry.pattern}${entry.is_frontend ? ' · Frontend' : ''}`,
        }))
    );
    const voxRoutes = computed(() => page.props.vox?.routes);

    const statusToggleOptions = computed<ToggleOption[]>(() => [
        { value: '', label: 'All' },
        { value: 'new', label: 'New' },
        { value: 'updated', label: 'Updated' },
        { value: 'missing', label: 'Missing' },
        { value: 'orphan', label: 'Orphan' },
        { value: 'dynamic', label: 'Dynamic usage' },
        { value: 'retained', label: 'Retained by rule' },
        { value: 'ignored', label: 'Ignored' },
        { value: 'pending-deletion', label: 'Pending deletion' },
        { value: 'pending', label: 'Pending' },
        { value: 'approved', label: 'Approved' },
    ]);

    const perPage = ref(translations.value.per_page ?? 25);
    const groupSearch = ref('');
    const selectedGroup = ref<string | null>(page.props.filters?.group ?? null);
    const search = ref(page.props.filters?.search ?? '');
    const status = ref(page.props.filters?.status ?? '');
    const sort = ref(page.props.filters?.sort ?? 'updated_desc');
    const scope = ref(page.props.filters?.scope ?? (selectedGroup.value ? 'group' : 'all'));

    const editTranslation = ref<TranslationItem | null>(null);
    const editValues = ref<Record<string, string>>({});
    const isSaving = ref(false);
    const usingApplicationLocale = ref<string | null>(null);
    const isTranslating = ref(false);
    const actionError = ref<string | null>(null);
    const toast = ref<{ message: string; tone: 'success' | 'error' } | null>(null);
    const selectedIds = ref<number[]>([]);
    const isBulkUpdating = ref(false);
    const isBulkTranslating = ref(false);
    const showOccurrences = ref(false);
    const searchDebounceTimer = ref<ReturnType<typeof setTimeout> | null>(null);
    const toastTimer = ref<ReturnType<typeof setTimeout> | null>(null);
    const isCreatingDynamic = ref(false);
    const isStoringDynamic = ref(false);
    const isTranslatingDynamic = ref(false);
    const newDynamicPattern = ref('');
    const newDynamicKey = ref('');
    const newDynamicValues = ref<Record<string, string>>({});
    const newDynamicError = ref<string | null>(null);
    const dynamicPatternPrefix = computed(() => newDynamicPattern.value.split('*', 1)[0] ?? '');

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

    const orderedLocales = computed(() => [
        baseLocale.value,
        ...locales.value.filter((locale) => locale !== baseLocale.value).sort(),
    ]);

    onMounted(() => {
        window.addEventListener('scroll', handleScroll, { passive: true });
    });

    onUnmounted(() => {
        window.removeEventListener('scroll', handleScroll);

        if (searchDebounceTimer.value) {
            clearTimeout(searchDebounceTimer.value);
        }

        if (toastTimer.value) {
            clearTimeout(toastTimer.value);
        }
    });

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
    const newDynamicBaseValue = computed(() => newDynamicValues.value[baseLocale.value] ?? '');
    const missingDynamicTargetLocales = computed(() =>
        targetLocales.value.filter((locale) => (newDynamicValues.value[locale] ?? '').trim() === '')
    );
    const canTranslateDynamic = computed(
        () =>
            aiStatus.value.available &&
            newDynamicBaseValue.value.trim() !== '' &&
            missingDynamicTargetLocales.value.length > 0
    );
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
            if (usingApplicationLocale.value === null) {
                editValues.value = buildEditValues(updated);
            }
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
    }

    function openDynamicCreate(): void {
        newDynamicPattern.value = dynamicPatterns.value[0]?.pattern ?? '';
        newDynamicKey.value = '';
        newDynamicValues.value = Object.fromEntries(locales.value.map((locale) => [locale, '']));
        newDynamicError.value = null;
        isCreatingDynamic.value = true;
    }

    function closeDynamicCreate(): void {
        if (isStoringDynamic.value || isTranslatingDynamic.value) {
            return;
        }

        isCreatingDynamic.value = false;
        newDynamicPattern.value = '';
        newDynamicKey.value = '';
        newDynamicValues.value = {};
        newDynamicError.value = null;
    }

    function translateMissingDynamicValues(): void {
        if (!canTranslateDynamic.value) {
            return;
        }

        isTranslatingDynamic.value = true;
        newDynamicError.value = null;

        router.post(
            voxRoutes.value?.manage_translation_translate_draft ?? '',
            {
                locales: missingDynamicTargetLocales.value,
                base_value: newDynamicBaseValue.value,
                key: newDynamicKey.value.trim(),
            },
            {
                preserveScroll: true,
                preserveState: true,
                onError: (errors) => {
                    newDynamicError.value = Object.values(errors)[0] ?? 'Unable to translate the target values.';
                },
                onSuccess: (successPage) => {
                    const translatedValues = successPage.flash?.translated_values as Record<string, string> | undefined;

                    if (translatedValues) {
                        Object.entries(translatedValues).forEach(([locale, value]) => {
                            newDynamicValues.value[locale] = value;
                        });
                    }
                },
                onFinish: () => {
                    isTranslatingDynamic.value = false;
                },
            }
        );
    }

    function storeDynamicTranslation(): void {
        if (newDynamicPattern.value === '' || newDynamicKey.value.trim() === '') {
            return;
        }

        isStoringDynamic.value = true;
        newDynamicError.value = null;

        router.post(
            voxRoutes.value?.manage_translation_store ?? '',
            {
                pattern: newDynamicPattern.value,
                key: newDynamicKey.value.trim(),
                values: newDynamicValues.value,
            },
            {
                preserveScroll: true,
                onError: (errors) => {
                    newDynamicError.value = Object.values(errors)[0] ?? 'Unable to create the dynamic translation.';
                },
                onSuccess: (successPage) => {
                    const message =
                        (successPage.flash?.success as string | undefined) ?? 'Dynamic translation created.';
                    isStoringDynamic.value = false;
                    closeDynamicCreate();
                    showToast(message);
                },
                onFinish: () => {
                    isStoringDynamic.value = false;
                },
            }
        );
    }

    function closeEdit(): void {
        editTranslation.value = null;
        editValues.value = {};
        actionError.value = null;
    }

    function handleError(errors: Record<string, string>): void {
        actionError.value = Object.values(errors)[0] ?? 'Request failed.';
    }

    function dismissToast(): void {
        if (toastTimer.value) {
            clearTimeout(toastTimer.value);
            toastTimer.value = null;
        }

        toast.value = null;
    }

    function showToast(message: string, tone: 'success' | 'error' = 'success'): void {
        dismissToast();
        toast.value = { message, tone };
        toastTimer.value = setTimeout(() => {
            toast.value = null;
            toastTimer.value = null;
        }, 5000);
    }

    async function copyDynamicPatternPrefix(): Promise<void> {
        if (dynamicPatternPrefix.value === '') {
            return;
        }

        if (await copyTextToClipboard(dynamicPatternPrefix.value)) {
            showToast('Dynamic key prefix copied.');

            return;
        }

        showToast('Unable to copy the dynamic key prefix.', 'error');
    }

    function handleBulkError(errors: Record<string, string>): void {
        showToast(Object.values(errors)[0] ?? 'Request failed.', 'error');
    }

    function workflowStatusLabel(translation: TranslationItem): string {
        return translation.status === 'approved' ? 'Approved' : 'Pending review';
    }

    function frontendGroupTooltip(group: GroupItem): string {
        if (group.frontend_export_source === 'json') {
            return 'JSON translations are included in the frontend bundle';
        }

        if (group.frontend_export_source === 'configured') {
            return 'Included in the frontend bundle by configuration';
        }

        return 'Automatically included from frontend source usage';
    }

    function canDelete(translation: TranslationItem): boolean {
        return translation.deletion_unavailable_reason === null;
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

    const cleanupRows = ref<TranslationItem[]>([]);
    const cleanupAction = ref<'delete' | 'ignore' | 'restore'>('delete');
    const cleanupConfirmation = ref('');
    const cleanupError = ref('');
    const isCleaning = ref(false);
    const cleanupTitle = computed(
        () => ({ delete: 'Delete keys', ignore: 'Ignore in Vox', restore: 'Restore keys' })[cleanupAction.value]
    );
    function reviewCleanup(action: 'delete' | 'ignore' | 'restore', rows?: TranslationItem[]): void {
        cleanupAction.value = action;
        cleanupRows.value = rows ?? translations.value.data.filter((row) => selectedIds.value.includes(row.id));
        cleanupConfirmation.value = '';
        cleanupError.value = '';
    }
    function submitCleanup(): void {
        isCleaning.value = true;
        router.post(
            voxRoutes.value?.manage_translation_cleanup ?? '',
            {
                ids: cleanupRows.value.map((row) => row.id),
                action: cleanupAction.value,
                confirmation: cleanupAction.value === 'delete' ? 'CONFIRM' : cleanupConfirmation.value,
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    cleanupRows.value = [];
                    selectedIds.value = [];
                    closeEdit();
                    showToast('Translation selection updated.');
                },
                onError: (errors) => {
                    cleanupError.value = Object.values(errors)[0] ?? 'Unable to update the selection.';
                },
                onFinish: () => {
                    isCleaning.value = false;
                },
            }
        );
    }

    function bulkApproval(targetStatus: 'pending' | 'approved'): void {
        if (selectedIds.value.length === 0) {
            return;
        }

        isBulkUpdating.value = true;
        actionError.value = null;

        router.post(
            voxRoutes.value?.manage_translation_bulk_approval ?? '',
            {
                ids: selectedIds.value,
                status: targetStatus,
            },
            {
                preserveScroll: true,
                preserveState: true,
                onError: handleBulkError,
                onSuccess: (successPage) => {
                    showToast((successPage.flash?.success as string | undefined) ?? 'Translation statuses updated.');
                    selectedIds.value = [];
                },
                onFinish: () => {
                    isBulkUpdating.value = false;
                },
            }
        );
    }

    function bulkTranslateMissing(): void {
        if (selectedIds.value.length === 0 || !aiStatus.value.available) {
            return;
        }

        isBulkTranslating.value = true;

        router.post(
            voxRoutes.value?.manage_translation_bulk_translate ?? '',
            { ids: selectedIds.value },
            {
                preserveScroll: true,
                preserveState: true,
                onError: handleBulkError,
                onSuccess: (successPage) => {
                    showToast(
                        (successPage.flash?.success as string | undefined) ?? 'Missing translations were translated.'
                    );
                },
                onFinish: () => {
                    isBulkTranslating.value = false;
                },
            }
        );
    }

    function translationActionUrl(route: string | undefined, translationId: number): string {
        return route?.replace('__translation__', String(translationId)) ?? '';
    }

    function toggleApproval(translation: TranslationItem): void {
        actionError.value = null;

        router.post(
            translationActionUrl(voxRoutes.value?.manage_translation_toggle_approval, translation.id),
            {},
            {
                preserveScroll: true,
                preserveState: true,
                onError: handleBulkError,
                onSuccess: (successPage) => {
                    showToast((successPage.flash?.success as string | undefined) ?? 'Translation status updated.');
                },
            }
        );
    }

    function useApplicationWording(locale: string): void {
        if (!editTranslation.value || usingApplicationLocale.value !== null) {
            return;
        }

        actionError.value = null;
        usingApplicationLocale.value = locale;

        router.post(
            translationActionUrl(voxRoutes.value?.manage_translation_use_application, editTranslation.value.id),
            { locale },
            {
                preserveScroll: true,
                preserveState: true,
                onError: handleError,
                onSuccess: (successPage) => {
                    showToast((successPage.flash?.success as string | undefined) ?? 'Application wording restored.');
                },
                onFinish: async () => {
                    await nextTick();
                    usingApplicationLocale.value = null;
                },
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
            translationActionUrl(voxRoutes.value?.manage_translation_update, editTranslation.value.id),
            { values: editValues.value },
            {
                preserveScroll: true,
                onFinish: () => {
                    isSaving.value = false;
                },
                onError: handleError,
                onSuccess: (successPage) => {
                    const message = (successPage.flash?.success as string | undefined) ?? 'Translations saved.';
                    closeEdit();
                    showToast(message);
                },
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
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <h1 class="text-2xl font-semibold tracking-tight">Manage Translations</h1>
                <p class="text-muted-foreground mt-1 text-sm">
                    Browse, edit, create dynamic values, and approve translations across all locales.
                </p>
            </div>
            <Button
                data-test="add-dynamic-translation"
                :disabled="dynamicPatterns.length === 0"
                class="shrink-0"
                variant="outline"
                @click="openDynamicCreate"
            >
                <Plus class="size-4" />
                Add dynamic translation
            </Button>
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
                                        v-if="group.is_frontend_exported"
                                        :text="frontendGroupTooltip(group)"
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
                                    v-if="group.is_frontend_exported"
                                    data-test="group-frontend-tooltip"
                                    :text="frontendGroupTooltip(group)"
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
                            class="flex flex-wrap items-center gap-2"
                        >
                            <Button
                                variant="outline"
                                @click="reviewCleanup('delete')"
                                >Delete keys</Button
                            >
                            <Button
                                variant="outline"
                                @click="reviewCleanup('ignore')"
                                >Ignore in Vox</Button
                            >
                            <Button
                                variant="outline"
                                @click="reviewCleanup('restore')"
                                >Restore</Button
                            >
                            <Button
                                data-test="bulk-translate-missing"
                                :disabled="isBulkUpdating || isBulkTranslating || !aiStatus.available"
                                size="sm"
                                variant="outline"
                                @click="bulkTranslateMissing"
                            >
                                <Sparkles class="size-4" />
                                {{ isBulkTranslating ? 'Translating…' : 'AI translate missing' }}
                            </Button>
                            <Button
                                :disabled="isBulkUpdating || isBulkTranslating"
                                size="sm"
                                variant="outline"
                                @click="bulkApproval('pending')"
                            >
                                Return to review
                            </Button>
                            <Button
                                :disabled="isBulkUpdating || isBulkTranslating"
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
                                        <Badge
                                            v-if="translation.is_orphan"
                                            class="shrink-0"
                                            variant="warning"
                                        >
                                            Orphan
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
                                    <Badge
                                        v-if="translation.is_retained"
                                        variant="secondary"
                                        >Retained</Badge
                                    >
                                    <Badge
                                        v-if="translation.is_ignored && !translation.is_pending_delete"
                                        variant="warning"
                                        >Ignored</Badge
                                    >
                                    <Badge
                                        v-if="translation.is_pending_delete"
                                        variant="warning"
                                        >Pending deletion</Badge
                                    >
                                    <Tooltip
                                        v-if="translation.is_dynamic"
                                        :text="`Possible dynamic usage: ${translation.dynamic_pattern}`"
                                    >
                                        <Braces
                                            aria-label="Dynamic translation key"
                                            class="size-3 text-violet-500"
                                        />
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
                                    <Tooltip
                                        v-if="canDelete(translation)"
                                        text="Delete key"
                                    >
                                        <Button
                                            aria-label="Delete key"
                                            data-test="delete-translation"
                                            class="text-destructive size-8"
                                            size="icon"
                                            variant="ghost"
                                            :disabled="isCleaning || !canDelete(translation)"
                                            @click.stop="reviewCleanup('delete', [translation])"
                                        >
                                            <Trash2 class="size-4" />
                                        </Button>
                                    </Tooltip>
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
                        class="bg-muted/30 flex flex-wrap items-center justify-between gap-3 border-t px-4 py-3"
                    >
                        <p class="text-muted-foreground text-xs">
                            Page {{ pagination.current_page }} of {{ pagination.last_page }}
                            <span class="hidden sm:inline">• {{ pagination.total }} items</span>
                        </p>
                        <PageSizeSelect
                            v-model="perPage"
                            @update:model-value="applyFilters(1)"
                        />
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

    <!-- Create Dynamic Translation Panel -->
    <SlidePanel
        :open="isCreatingDynamic"
        subtitle="Runtime-resolved key"
        title="Add dynamic translation"
        @close="closeDynamicCreate"
    >
        <div
            data-test="dynamic-create-panel"
            class="sr-only"
        >
            Dynamic translation editor
        </div>
        <div
            v-if="newDynamicError"
            role="alert"
            class="text-destructive border-destructive/40 bg-destructive/10 mb-5 rounded-lg border p-3 text-sm"
        >
            {{ newDynamicError }}
        </div>

        <div class="space-y-5">
            <FormField
                id="new_dynamic_pattern"
                description="Choose the active pattern that covers this concrete value. Copy its stable prefix to start the key."
                label="Dynamic pattern"
            >
                <div class="flex items-center gap-2">
                    <div class="min-w-0 flex-1">
                        <Select
                            id="new_dynamic_pattern"
                            v-model="newDynamicPattern"
                            :options="dynamicPatternOptions"
                            placeholder="Choose a pattern"
                        />
                    </div>
                    <Tooltip
                        text="Copy the pattern prefix"
                        align="end"
                    >
                        <Button
                            aria-label="Copy the pattern prefix"
                            data-test="copy-dynamic-prefix"
                            :disabled="!dynamicPatternPrefix"
                            size="icon"
                            variant="outline"
                            @click="copyDynamicPatternPrefix"
                        >
                            <Copy class="size-4" />
                        </Button>
                    </Tooltip>
                </div>
            </FormField>

            <FormField
                id="new_dynamic_key"
                :description="
                    newDynamicPattern
                        ? `Enter the complete Laravel key matching ${newDynamicPattern}.`
                        : 'Enter the complete Laravel translation key.'
                "
                label="Concrete translation key"
            >
                <Input
                    id="new_dynamic_key"
                    v-model="newDynamicKey"
                    data-test="new-dynamic-key"
                    placeholder="enums.user_roles.admin"
                />
            </FormField>

            <div class="border-t pt-5">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <p class="text-sm font-semibold">Translation values</p>
                        <p class="text-muted-foreground mt-1 text-xs">
                            Enter the required base value, then fill target locales manually or with AI before creating
                            the translation.
                        </p>
                    </div>
                    <Button
                        v-if="aiStatus.available"
                        data-test="translate-dynamic-missing"
                        :disabled="!canTranslateDynamic || isTranslatingDynamic || isStoringDynamic"
                        class="shrink-0"
                        size="sm"
                        variant="outline"
                        @click="translateMissingDynamicValues"
                    >
                        <Sparkles class="size-4" />
                        {{ isTranslatingDynamic ? 'Translating…' : 'AI fill missing' }}
                    </Button>
                </div>

                <div class="mt-4 space-y-4">
                    <FormField
                        v-for="locale in orderedLocales"
                        :id="`new_dynamic_value_${locale}`"
                        :key="locale"
                        :description="locale === baseLocale ? 'Required source value' : undefined"
                        :label="locale.toUpperCase()"
                    >
                        <Textarea
                            :id="`new_dynamic_value_${locale}`"
                            v-model="newDynamicValues[locale]"
                            :data-test="`new-dynamic-value-${locale}`"
                            :rows="2"
                        />
                    </FormField>
                </div>
            </div>
        </div>

        <template #footer>
            <div class="flex items-center justify-end gap-2">
                <Button
                    :disabled="isStoringDynamic || isTranslatingDynamic"
                    variant="outline"
                    @click="closeDynamicCreate"
                >
                    Cancel
                </Button>
                <Button
                    data-test="store-dynamic-translation"
                    :disabled="
                        isStoringDynamic ||
                        isTranslatingDynamic ||
                        newDynamicPattern === '' ||
                        newDynamicKey.trim() === '' ||
                        !(newDynamicValues[baseLocale] ?? '').trim()
                    "
                    @click="storeDynamicTranslation"
                >
                    {{ isStoringDynamic ? 'Creating…' : 'Create translation' }}
                </Button>
            </div>
        </template>
    </SlidePanel>

    <!-- Edit Panel -->
    <SlidePanel
        :open="!!editTranslation"
        :subtitle="editTranslation?.group ?? 'default'"
        :title="editTranslation?.display_key"
        @close="closeEdit"
    >
        <template #header>
            <div
                data-test="translation-edit-panel"
                class="min-w-0 flex-1"
            >
                <p class="text-muted-foreground text-xs tracking-[0.2em] uppercase">Editing</p>
                <h2 class="mt-1 truncate text-lg font-semibold">{{ editTranslation?.display_key }}</h2>
                <div class="mt-1 flex flex-wrap items-center gap-2 text-xs">
                    <Badge variant="secondary">{{ editTranslation?.group ?? 'default' }}</Badge>
                    <Badge
                        v-if="editTranslation?.is_orphan"
                        variant="warning"
                    >
                        Orphan
                    </Badge>
                    <Badge
                        v-if="editTranslation?.is_dynamic"
                        variant="secondary"
                    >
                        Dynamic usage · {{ editTranslation.dynamic_pattern }}
                    </Badge>
                    <Badge
                        v-if="editTranslation?.is_retained"
                        variant="secondary"
                        >Retained by rule</Badge
                    >
                    <Badge
                        v-if="editTranslation?.is_ignored"
                        variant="warning"
                        >{{ editTranslation?.is_pending_delete ? 'Pending deletion' : 'Ignored' }}</Badge
                    >
                    <Badge
                        v-if="editTranslation?.occurrences.length"
                        variant="secondary"
                        >Static usage</Badge
                    >
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
                <div
                    v-if="editTranslation?.published_overrides?.[locale] != null"
                    class="bg-muted/40 space-y-3 rounded-lg border p-3 text-sm"
                >
                    <div>
                        <p class="text-muted-foreground text-xs font-medium">Live Vox override</p>
                        <p class="mt-1 [overflow-wrap:anywhere] whitespace-pre-wrap">
                            {{ editTranslation.published_overrides[locale] }}
                        </p>
                    </div>
                    <div>
                        <p class="text-muted-foreground text-xs font-medium">Application wording</p>
                        <p class="mt-1 [overflow-wrap:anywhere] whitespace-pre-wrap">
                            {{ editTranslation.file_values?.[locale] ?? 'No application value' }}
                        </p>
                    </div>
                    <Button
                        :data-test="`use-application-wording-${locale}`"
                        :disabled="usingApplicationLocale !== null || isSaving || isTranslating"
                        size="sm"
                        variant="outline"
                        @click="useApplicationWording(locale)"
                    >
                        {{ usingApplicationLocale === locale ? 'Restoring…' : 'Use application wording' }}
                    </Button>
                    <p class="text-muted-foreground text-xs">Removes this live override and keeps your draft.</p>
                </div>
                <Textarea
                    v-model="editValues[locale]"
                    :data-test="`translation-value-${locale}`"
                    :rows="2"
                    class="resize-none"
                />
            </div>
        </div>

        <div
            v-if="editTranslation?.matching_patterns.length"
            class="mt-4 space-y-2 border-t pt-4 text-sm"
        >
            <p class="font-semibold">Matching rules</p>
            <p
                v-for="pattern in editTranslation.matching_patterns"
                :key="pattern"
                class="font-mono"
            >
                {{ pattern }}
            </p>
            <p class="text-muted-foreground">Sources: {{ editTranslation.retention_sources.join(', ') }}</p>
        </div>
        <div
            v-if="editTranslation?.dynamic_occurrences.length"
            class="mt-4 space-y-2 border-t pt-4 text-sm"
        >
            <p class="font-semibold">Possible dynamic matches ({{ editTranslation.dynamic_occurrences.length }})</p>
            <p class="text-muted-foreground">
                These expressions match a pattern; they do not prove this exact key is used.
            </p>
            <div
                v-for="(occurrence, index) in editTranslation.dynamic_occurrences"
                :key="index"
                class="bg-muted/40 rounded-lg border p-3"
            >
                <p>{{ occurrence.file }}:{{ occurrence.line }}</p>
                <code>{{ occurrence.context }}</code>
            </div>
        </div>
        <div
            v-if="editTranslation"
            class="mt-4 flex flex-wrap gap-2 border-t pt-4"
        >
            <p
                v-if="editTranslation.deletion_unavailable_reason"
                class="text-muted-foreground w-full text-sm"
            >
                {{ editTranslation.deletion_unavailable_reason }}
            </p>
            <Button
                v-if="canDelete(editTranslation)"
                variant="outline"
                @click="reviewCleanup('delete', [editTranslation])"
                >Delete key</Button
            >
            <Button
                v-if="!editTranslation.is_ignored"
                variant="outline"
                @click="reviewCleanup('ignore', [editTranslation])"
                >Ignore in Vox</Button
            >
            <Button
                v-else
                variant="outline"
                @click="reviewCleanup('restore', [editTranslation])"
                >Restore key</Button
            >
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
                    Exact occurrences ({{ editTranslation.occurrences.length }})
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
                    :disabled="isSaving || usingApplicationLocale !== null || editTranslation?.is_ignored"
                    @click="saveEdit"
                >
                    {{ isSaving ? 'Saving…' : 'Save changes' }}
                </Button>
            </div>
        </template>
    </SlidePanel>

    <SlidePanel
        :open="cleanupRows.length > 0"
        :title="cleanupTitle"
        @close="!isCleaning && (cleanupRows = [])"
    >
        <p>
            {{ cleanupRows.length }} keys and
            {{ cleanupRows.reduce((count, row) => count + row.values_count, 0) }} locale values selected.
        </p>
        <p class="mt-3 font-semibold">
            {{
                cleanupAction === 'delete'
                    ? 'Keys will be marked for deletion in Vox. Language files remain unchanged until Publish.'
                    : 'Published language files will not be changed.'
            }}
        </p>
        <p
            v-if="cleanupAction === 'delete'"
            class="mt-3"
        >
            Publish will permanently remove the selected keys, their values, and related reconciliation records. Only
            current orphans and dynamic keys without direct static references qualify. Dynamic usage cannot be
            conclusively checked. A future discovery or binding may recreate a deleted key.
        </p>
        <p
            v-else-if="cleanupAction === 'ignore'"
            class="mt-3"
        >
            Ignored keys remain available to restore. Vox will skip their sync updates and publishing. Existing
            application translations remain in use.
        </p>
        <p
            v-else
            class="mt-3"
        >
            These keys will return to normal management. Run Sync to refresh their values and usage.
        </p>
        <ul class="my-4 space-y-1">
            <li
                v-for="row in cleanupRows"
                :key="row.id"
                class="font-mono text-xs break-all"
            >
                {{ row.display_key }}
            </li>
        </ul>
        <FormField
            v-if="cleanupAction !== 'delete'"
            label="Type CONFIRM to continue"
            ><Input
                id="cleanup-confirmation"
                v-model="cleanupConfirmation"
        /></FormField>
        <p
            v-if="cleanupError"
            role="alert"
            class="text-destructive mt-3"
        >
            {{ cleanupError }}
        </p>
        <template #footer
            ><Button
                :disabled="isCleaning || (cleanupAction !== 'delete' && cleanupConfirmation !== 'CONFIRM')"
                data-test="confirm-cleanup"
                @click="submitCleanup"
                >{{ isCleaning ? 'Working…' : cleanupTitle }}</Button
            ></template
        >
    </SlidePanel>

    <Transition
        enter-active-class="transition duration-200 ease-out"
        enter-from-class="translate-y-2 opacity-0"
        enter-to-class="translate-y-0 opacity-100"
        leave-active-class="transition duration-150 ease-in"
        leave-from-class="translate-y-0 opacity-100"
        leave-to-class="translate-y-2 opacity-0"
    >
        <div
            v-if="toast"
            data-test="success-toast"
            :role="toast.tone === 'error' ? 'alert' : 'status'"
            :aria-live="toast.tone === 'error' ? 'assertive' : 'polite'"
            :class="
                cn(
                    'bg-card fixed top-4 right-4 z-[70] flex max-w-[calc(100vw-2rem)] items-center gap-3 rounded-lg border px-4 py-3 text-sm shadow-lg sm:max-w-md',
                    toast.tone === 'error'
                        ? 'border-destructive/40 text-destructive'
                        : 'border-emerald-500/40 text-emerald-600'
                )
            "
        >
            <CircleAlert
                v-if="toast.tone === 'error'"
                class="size-4 shrink-0"
            />
            <Check
                v-else
                class="size-4 shrink-0"
            />
            <span class="min-w-0 flex-1">{{ toast.message }}</span>
            <button
                aria-label="Dismiss notification"
                class="rounded-sm opacity-70 transition-opacity hover:opacity-100 focus-visible:outline-2 focus-visible:outline-offset-2"
                type="button"
                @click="dismissToast"
            >
                <X class="size-4" />
            </button>
        </div>
    </Transition>
</template>
