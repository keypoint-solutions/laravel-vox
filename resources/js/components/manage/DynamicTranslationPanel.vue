<script lang="ts" setup>
    import { router } from '@inertiajs/vue3';
    import { Sparkles } from '@lucide/vue';
    import { computed, ref, watch } from 'vue';

    import type { SelectOption } from '@/components/ui';
    import { Button, FormField, Input, Select, SlidePanel, Textarea } from '@/components/ui';
    import Alert from '@/components/ui/Alert.vue';
    import { firstError, flashSuccess } from '@/lib/inertia';
    import type { DynamicPattern } from '@/types/manage';

    const props = defineProps<{
        open: boolean;
        patterns: DynamicPattern[];
        locales: string[];
        baseLocale: string;
        aiAvailable: boolean;
        storeRoute: string | undefined;
        translateDraftRoute: string | undefined;
    }>();

    const emit = defineEmits<{
        close: [];
        created: [message: string];
    }>();

    const isStoring = ref(false);
    const isTranslating = ref(false);
    const pattern = ref('');
    const key = ref('');
    const values = ref<Record<string, string>>({});
    const error = ref<string | null>(null);

    const patternOptions = computed<SelectOption[]>(() =>
        props.patterns.map((entry) => ({
            value: entry.pattern,
            label: `${entry.pattern}${entry.is_frontend ? ' · Frontend' : ''}`,
        }))
    );
    const patternPrefix = computed(() => pattern.value.split('*', 1)[0] ?? '');
    const fullKey = computed(() => patternPrefix.value + key.value.trim());
    const baseValue = computed(() => values.value[props.baseLocale] ?? '');
    const missingTargetLocales = computed(() =>
        props.locales.filter((locale) => locale !== props.baseLocale && (values.value[locale] ?? '').trim() === '')
    );
    const canTranslate = computed(
        () => props.aiAvailable && baseValue.value.trim() !== '' && missingTargetLocales.value.length > 0
    );

    watch(
        () => props.open,
        (open) => {
            pattern.value = open ? (props.patterns[0]?.pattern ?? '') : '';
            key.value = '';
            values.value = open ? Object.fromEntries(props.locales.map((locale) => [locale, ''])) : {};
            error.value = null;
        }
    );

    function close(): void {
        if (isStoring.value || isTranslating.value) {
            return;
        }

        emit('close');
    }

    function translateMissing(): void {
        if (!canTranslate.value) {
            return;
        }

        isTranslating.value = true;
        error.value = null;

        router.post(
            props.translateDraftRoute ?? '',
            {
                locales: missingTargetLocales.value,
                base_value: baseValue.value,
                key: fullKey.value,
            },
            {
                preserveScroll: true,
                preserveState: true,
                onError: (errors) => {
                    error.value = firstError(errors, 'Unable to translate the target values.');
                },
                onSuccess: (successPage) => {
                    const translatedValues = successPage.flash?.translated_values as Record<string, string> | undefined;

                    if (translatedValues) {
                        Object.entries(translatedValues).forEach(([locale, value]) => {
                            values.value[locale] = value;
                        });
                    }
                },
                onFinish: () => {
                    isTranslating.value = false;
                },
            }
        );
    }

    function store(): void {
        if (pattern.value === '' || key.value.trim() === '') {
            return;
        }

        isStoring.value = true;
        error.value = null;

        router.post(
            props.storeRoute ?? '',
            {
                pattern: pattern.value,
                key: fullKey.value,
                values: values.value,
            },
            {
                preserveScroll: true,
                onError: (errors) => {
                    error.value = firstError(errors, 'Unable to create the dynamic translation.');
                },
                onSuccess: (successPage) => {
                    isStoring.value = false;
                    emit('close');
                    emit('created', flashSuccess(successPage, 'Dynamic translation created.'));
                },
                onFinish: () => {
                    isStoring.value = false;
                },
            }
        );
    }
</script>

<template>
    <SlidePanel
        :open="open"
        subtitle="Runtime-resolved key"
        title="Add dynamic translation"
        @close="close"
    >
        <div
            data-test="dynamic-create-panel"
            class="sr-only"
        >
            Dynamic translation editor
        </div>
        <Alert
            v-if="error"
            tone="error"
            class="mb-5"
        >
            {{ error }}
        </Alert>

        <div class="space-y-5">
            <FormField
                id="new_dynamic_pattern"
                description="Choose the pattern for the new translation. Its fixed prefix is added automatically."
                label="Dynamic pattern"
            >
                <Select
                    id="new_dynamic_pattern"
                    v-model="pattern"
                    :options="patternOptions"
                    placeholder="Choose a pattern"
                />
            </FormField>

            <FormField
                id="new_dynamic_key"
                :description="
                    patternPrefix
                        ? 'Enter only the part after the fixed prefix.'
                        : 'Enter a concrete key matching the selected pattern.'
                "
                label="New key"
            >
                <p
                    v-if="patternPrefix"
                    class="text-muted-foreground font-mono text-xs break-all"
                >
                    Prefix: {{ patternPrefix }}
                </p>
                <Input
                    id="new_dynamic_key"
                    v-model="key"
                    data-test="new-dynamic-key"
                    :disabled="!pattern"
                    :placeholder="pattern.slice(patternPrefix.length).replaceAll('*', 'name') || 'name'"
                />
                <p
                    v-if="key.trim()"
                    data-test="new-dynamic-full-key"
                    class="text-muted-foreground text-xs break-all"
                >
                    Full key: <span class="font-mono">{{ fullKey }}</span>
                </p>
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
                        v-if="aiAvailable"
                        data-test="translate-dynamic-missing"
                        :disabled="!canTranslate || isTranslating || isStoring"
                        class="shrink-0"
                        size="sm"
                        variant="outline"
                        @click="translateMissing"
                    >
                        <Sparkles class="size-4" />
                        {{ isTranslating ? 'Translating…' : 'AI fill missing' }}
                    </Button>
                </div>

                <div class="mt-4 space-y-4">
                    <FormField
                        v-for="locale in locales"
                        :id="`new_dynamic_value_${locale}`"
                        :key="locale"
                        :description="locale === baseLocale ? 'Required source value' : undefined"
                        :label="locale.toUpperCase()"
                    >
                        <Textarea
                            :id="`new_dynamic_value_${locale}`"
                            v-model="values[locale]"
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
                    :disabled="isStoring || isTranslating"
                    variant="outline"
                    @click="close"
                >
                    Cancel
                </Button>
                <Button
                    data-test="store-dynamic-translation"
                    :disabled="
                        isStoring ||
                        isTranslating ||
                        pattern === '' ||
                        key.trim() === '' ||
                        !(values[baseLocale] ?? '').trim()
                    "
                    @click="store"
                >
                    {{ isStoring ? 'Creating…' : 'Create translation' }}
                </Button>
            </div>
        </template>
    </SlidePanel>
</template>
