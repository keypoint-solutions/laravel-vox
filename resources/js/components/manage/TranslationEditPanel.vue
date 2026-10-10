<script lang="ts" setup>
    import { router } from '@inertiajs/vue3';
    import { ChevronRight, Code, FileText, Sparkles } from '@lucide/vue';
    import { computed, nextTick, ref, watch } from 'vue';

    import FallbackChoice from '@/components/FallbackChoice.vue';
    import { Badge, Button, SlidePanel, Textarea } from '@/components/ui';
    import Alert from '@/components/ui/Alert.vue';
    import { useDateTime } from '@/composables/useDateTime';
    import { firstError, flashSuccess } from '@/lib/inertia';
    import { canDelete, translationActionUrl } from '@/lib/manage';
    import type { CleanupAction, FallbackRule, TranslationItem } from '@/types/manage';

    const props = defineProps<{
        translation: TranslationItem | null;
        locales: string[];
        baseLocale: string;
        aiAvailable: boolean;
        fallbackRules: FallbackRule[];
        missingTranslationPrefix: string;
        updateRoute: string | undefined;
        translateRoute: string | undefined;
        useApplicationRoute: string | undefined;
    }>();

    const emit = defineEmits<{
        close: [];
        saved: [message: string];
        notify: [message: string];
        cleanup: [action: CleanupAction, translation: TranslationItem];
    }>();

    const { formatDateTime } = useDateTime();

    const values = ref<Record<string, string>>({});
    const isSaving = ref(false);
    const isTranslating = ref(false);
    const isTranslatingValues = ref(false);
    const usingApplicationLocale = ref<string | null>(null);
    const error = ref<string | null>(null);
    const showOccurrences = ref(false);

    const targetLocales = computed(() => props.locales.filter((locale) => locale !== props.baseLocale));
    const baseLocaleValue = computed(() => (props.translation ? (values.value[props.baseLocale] ?? '') : ''));
    const baseIsUsableSource = computed(() => {
        const value = baseLocaleValue.value;

        return (
            value.trim() !== '' &&
            !isFlagged(value) &&
            !(props.translation?.is_placeholder_key && value === props.translation.key)
        );
    });
    const missingTargetLocales = computed(() =>
        targetLocales.value.filter((locale) => {
            if (props.translation?.fallback?.[locale]?.mode === 'default') return false;
            const value = values.value[locale] ?? '';

            return value.trim() === '' || isFlagged(value);
        })
    );
    const canTranslate = computed(
        () => props.aiAvailable && baseIsUsableSource.value && targetLocales.value.length > 0
    );

    watch(
        () => props.translation,
        (translation, previous) => {
            if (!translation) {
                values.value = {};
                error.value = null;

                return;
            }

            if (translation.id !== previous?.id) {
                showOccurrences.value = false;
                values.value = buildValues(translation);
                error.value = null;

                return;
            }

            // Keep unsaved AI output and in-flight edits when the listing refreshes underneath the panel.
            if (!isTranslatingValues.value && usingApplicationLocale.value === null) {
                values.value = buildValues(translation);
            }
        }
    );

    function buildValues(translation: TranslationItem): Record<string, string> {
        return Object.fromEntries(props.locales.map((locale) => [locale, translation.values?.[locale] ?? '']));
    }

    function isFlagged(value: string): boolean {
        const prefix = props.missingTranslationPrefix;

        return prefix !== '' && value.startsWith(prefix);
    }

    function localeStatus(locale: string): string {
        const translation = props.translation;

        if (!translation) {
            return '';
        }

        if (translation.fallback?.[locale]?.mode === 'default') {
            return 'Preserved local wording';
        }

        const blank = translation.blank_locales?.includes(locale);

        if (translation.draft_locales?.includes(locale)) {
            return blank ? 'Blank · awaiting approval' : 'Draft · awaiting approval';
        }

        if (translation.pending_publish_locales?.includes(locale)) {
            return blank ? 'Blank · approved, not yet published' : 'Approved · not yet published';
        }

        if (blank) {
            return 'Blank · current wording';
        }

        return (translation.values?.[locale] ?? '') === '' ? 'Not translated yet' : 'Current wording';
    }

    function handleError(errors: Record<string, string>): void {
        error.value = firstError(errors);
    }

    function useApplicationWording(locale: string): void {
        if (!props.translation || usingApplicationLocale.value !== null) {
            return;
        }

        error.value = null;
        usingApplicationLocale.value = locale;

        router.post(
            translationActionUrl(props.useApplicationRoute, props.translation.id),
            { locale },
            {
                preserveScroll: true,
                preserveState: true,
                onError: handleError,
                onSuccess: (successPage) => {
                    emit('notify', flashSuccess(successPage, 'Application wording restored.'));
                },
                onFinish: async () => {
                    await nextTick();
                    usingApplicationLocale.value = null;
                },
            }
        );
    }

    function save(): void {
        if (!props.translation) {
            return;
        }

        error.value = null;
        isSaving.value = true;

        router.patch(
            translationActionUrl(props.updateRoute, props.translation.id),
            { values: values.value },
            {
                preserveScroll: true,
                onFinish: () => {
                    isSaving.value = false;
                },
                onError: handleError,
                onSuccess: (successPage) => {
                    emit('saved', flashSuccess(successPage, 'Translations saved.'));
                },
            }
        );
    }

    function translateLocales(locales: string[]): void {
        if (!props.translation) {
            return;
        }

        error.value = null;
        isTranslating.value = true;
        isTranslatingValues.value = true;

        router.post(
            translationActionUrl(props.translateRoute, props.translation.id),
            {
                locales,
                base_value: values.value[props.baseLocale] ?? '',
            },
            {
                preserveScroll: true,
                preserveState: true,
                onFinish: () => {
                    isTranslating.value = false;
                    // Reset the flag after a short delay so the refresh watcher skips this response.
                    setTimeout(() => {
                        isTranslatingValues.value = false;
                    }, 100);
                },
                onError: handleError,
                onSuccess: (successPage) => {
                    const translatedValues = successPage.flash?.translated_values as Record<string, string> | undefined;
                    if (translatedValues) {
                        Object.entries(translatedValues).forEach(([locale, value]) => {
                            values.value[locale] = value;
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

    function translateMissing(): void {
        if (missingTargetLocales.value.length === 0) {
            return;
        }

        translateLocales(missingTargetLocales.value);
    }
</script>

<template>
    <SlidePanel
        :open="!!translation"
        :subtitle="translation?.group ?? 'default'"
        :title="translation?.display_key"
        @close="emit('close')"
    >
        <template #header>
            <div
                data-test="translation-edit-panel"
                class="min-w-0 flex-1"
            >
                <p class="text-muted-foreground text-xs tracking-[0.2em] uppercase">Editing</p>
                <h2 class="mt-1 truncate text-lg font-semibold">{{ translation?.display_key }}</h2>
                <div class="mt-1 flex flex-wrap items-center gap-2 text-xs">
                    <Badge variant="secondary">{{ translation?.group ?? 'default' }}</Badge>
                    <Badge
                        v-if="translation?.is_orphan"
                        variant="warning"
                    >
                        Orphan
                    </Badge>
                    <Badge
                        v-if="translation?.is_dynamic"
                        variant="secondary"
                    >
                        Dynamic usage · {{ translation.dynamic_pattern }}
                    </Badge>
                    <Badge
                        v-if="translation?.is_retained"
                        variant="secondary"
                        >Retained by rule</Badge
                    >
                    <Badge
                        v-if="translation?.is_pending_delete"
                        variant="warning"
                        >Pending deletion</Badge
                    >
                    <Badge
                        v-if="translation?.occurrences.length"
                        variant="secondary"
                        >Static usage</Badge
                    >
                    <span class="text-muted-foreground">Updated {{ formatDateTime(translation?.updated_at) }}</span>
                </div>
            </div>
        </template>

        <!-- Error Alert -->
        <Alert
            v-if="error"
            tone="error"
            class="mb-4"
        >
            {{ error }}
        </Alert>

        <!-- Locale Values -->
        <div class="space-y-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <p class="text-sm font-semibold">Translations</p>
                    <p class="text-muted-foreground text-xs">
                        Changes are saved as drafts. Approve and publish them to update the application. Clear a field
                        to leave it blank on purpose.
                    </p>
                </div>
                <div
                    v-if="aiAvailable"
                    class="flex w-full flex-wrap gap-2 sm:w-auto"
                >
                    <Button
                        :disabled="!canTranslate || isTranslating || missingTargetLocales.length === 0"
                        class="flex-auto sm:flex-none"
                        size="sm"
                        variant="outline"
                        title="Fill the empty or flagged fields using the base locale."
                        @click="translateMissing"
                    >
                        <Sparkles class="size-4" />
                        AI translate missing
                    </Button>
                    <Button
                        :disabled="!canTranslate || isTranslating"
                        class="flex-auto sm:flex-none"
                        size="sm"
                        variant="outline"
                        title="Replace all target locale values using the base locale."
                        @click="translateAll"
                    >
                        <Sparkles class="size-4" />
                        AI retranslate
                    </Button>
                </div>
            </div>

            <div
                v-for="locale in locales"
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
                        v-if="aiAvailable && locale !== baseLocale"
                        :disabled="
                            !canTranslate || isTranslating || translation?.fallback?.[locale]?.mode === 'default'
                        "
                        class="h-7 px-2"
                        size="sm"
                        variant="ghost"
                        @click="translateLocale(locale)"
                    >
                        <Sparkles class="size-3" />
                        AI
                    </Button>
                </div>
                <FallbackChoice
                    v-if="locale !== baseLocale && translation"
                    :key="translation.id + locale"
                    :locale="locale"
                    scope="key"
                    :translation-id="translation.id"
                    :selection="translation.fallback?.[locale]?.selection ?? 'inherit'"
                    :published="
                        fallbackRules.find(
                            (rule) =>
                                rule.scope === 'key' &&
                                rule.locale === locale &&
                                rule.group === (translation?.group ?? 'json') &&
                                rule.key === translation?.key
                        )?.published_mode ?? 'inherit'
                    "
                    :base-locale="baseLocale"
                />
                <div
                    v-if="translation?.fallback?.[locale]?.mode === 'default'"
                    class="bg-muted rounded-lg p-3 text-sm"
                >
                    <p class="font-medium">Using default · {{ baseLocale }}</p>
                    <p class="mt-1 whitespace-pre-wrap">
                        {{ values[baseLocale] || 'Default translation missing' }}
                    </p>
                    <p class="text-muted-foreground mt-1 text-xs">
                        {{
                            translation.fallback[locale].published_mode === 'default'
                                ? 'Default wording is published. Changes refresh on publish.'
                                : 'Pending publication. Current application wording is unchanged.'
                        }}
                    </p>
                </div>
                <div
                    v-if="
                        translation?.published_overrides?.[locale] != null &&
                        translation?.fallback?.[locale]?.published_mode !== 'default'
                    "
                    class="bg-muted/40 space-y-3 rounded-lg border p-3 text-sm"
                >
                    <div>
                        <p class="text-muted-foreground text-xs font-medium">Published override · currently live</p>
                        <p class="mt-1 [overflow-wrap:anywhere] whitespace-pre-wrap">
                            {{ translation.published_overrides[locale] }}
                        </p>
                    </div>
                    <div>
                        <p class="text-muted-foreground text-xs font-medium">
                            Application default · without the override
                        </p>
                        <p class="mt-1 [overflow-wrap:anywhere] whitespace-pre-wrap">
                            {{ translation.file_values?.[locale] ?? 'No application value' }}
                        </p>
                    </div>
                    <Button
                        :data-test="`use-application-wording-${locale}`"
                        :disabled="usingApplicationLocale !== null || isSaving || isTranslating"
                        size="sm"
                        variant="outline"
                        @click="useApplicationWording(locale)"
                    >
                        {{ usingApplicationLocale === locale ? 'Removing…' : 'Remove published override' }}
                    </Button>
                    <p class="text-muted-foreground text-xs">
                        {{
                            translation.published_overrides[locale] === translation.file_values?.[locale]
                                ? 'The override matches the application default. Removing it will not change the live wording.'
                                : 'Immediately restores the application default in the live translation files.'
                        }}
                        Any unpublished edits are preserved.
                    </p>
                </div>
                <p class="text-muted-foreground text-xs">
                    {{ localeStatus(locale) }}
                </p>
                <Textarea
                    v-model="values[locale]"
                    :data-test="`translation-value-${locale}`"
                    :disabled="translation?.fallback?.[locale]?.mode === 'default'"
                    :rows="2"
                    class="resize-none"
                />
            </div>
        </div>

        <div
            v-if="translation?.matching_patterns.length"
            class="mt-4 space-y-2 border-t pt-4 text-sm"
        >
            <p class="font-semibold">Matching rules</p>
            <p
                v-for="pattern in translation.matching_patterns"
                :key="pattern"
                class="font-mono"
            >
                {{ pattern }}
            </p>
            <p class="text-muted-foreground">Sources: {{ translation.retention_sources.join(', ') }}</p>
        </div>
        <div
            v-if="translation?.dynamic_occurrences.length"
            class="mt-4 space-y-2 border-t pt-4 text-sm"
        >
            <p class="font-semibold">Possible dynamic matches ({{ translation.dynamic_occurrences.length }})</p>
            <p class="text-muted-foreground">
                These expressions match a pattern; they do not prove this exact key is used.
            </p>
            <div
                v-for="(occurrence, index) in translation.dynamic_occurrences"
                :key="index"
                class="bg-muted/40 rounded-lg border p-3"
            >
                <p>{{ occurrence.file }}:{{ occurrence.line }}</p>
                <code>{{ occurrence.context }}</code>
            </div>
        </div>
        <div
            v-if="translation"
            class="mt-4 flex flex-wrap gap-2 border-t pt-4"
        >
            <p
                v-if="translation.deletion_unavailable_reason"
                class="text-muted-foreground w-full text-sm"
            >
                {{ translation.deletion_unavailable_reason }}
            </p>
            <Button
                v-if="canDelete(translation)"
                variant="outline"
                @click="emit('cleanup', 'delete', translation)"
                >Delete key</Button
            >
            <Button
                v-if="translation.is_pending_delete"
                variant="outline"
                @click="emit('cleanup', 'restore', translation)"
                >Cancel deletion</Button
            >
        </div>

        <!-- Occurrences Section -->
        <div
            v-if="translation?.occurrences?.length"
            class="mt-4 border-t pt-4"
        >
            <button
                class="flex w-full cursor-pointer items-center justify-between text-sm font-semibold"
                type="button"
                @click="showOccurrences = !showOccurrences"
            >
                <span class="flex items-center gap-2">
                    <FileText class="size-4" />
                    Exact occurrences ({{ translation.occurrences.length }})
                </span>
                <ChevronRight :class="['size-4 transition-transform', showOccurrences && 'rotate-90']" />
            </button>
            <div
                v-if="showOccurrences"
                class="mt-3 space-y-2"
            >
                <div
                    v-for="occurrence in translation.occurrences"
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
                    @click="emit('close')"
                >
                    Cancel
                </Button>
                <Button
                    :disabled="isSaving || usingApplicationLocale !== null || translation?.is_pending_delete"
                    @click="save"
                >
                    {{ isSaving ? 'Saving…' : 'Save changes' }}
                </Button>
            </div>
        </template>
    </SlidePanel>
</template>
