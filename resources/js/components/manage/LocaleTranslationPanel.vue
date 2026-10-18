<script lang="ts" setup>
    import { router } from '@inertiajs/vue3';
    import { computed, ref, watch } from 'vue';

    import type { SelectOption } from '@/components/ui';
    import { Button, FormField, Select, SlidePanel } from '@/components/ui';
    import { firstError } from '@/lib/inertia';

    interface LocaleTranslationBatch {
        locale: string;
        translated: number;
        total: number | null;
        next_after_id: number | null;
        failure: string | null;
    }

    const props = defineProps<{
        open: boolean;
        locales: string[];
        route: string | undefined;
    }>();

    const emit = defineEmits<{
        close: [];
        done: [message: string];
    }>();

    const locale = ref('');
    const running = ref(false);
    const stopRequested = ref(false);
    const total = ref<number | null>(null);
    const translated = ref(0);
    const error = ref('');

    const localeOptions = computed<SelectOption[]>(() => props.locales.map((code) => ({ value: code, label: code })));
    const progress = computed(() =>
        total.value ? Math.min(100, Math.round((translated.value / total.value) * 100)) : 0
    );

    watch(
        () => props.open,
        (isOpen) => {
            if (isOpen && !running.value) {
                locale.value = props.locales.includes(locale.value) ? locale.value : (props.locales[0] ?? '');
                total.value = null;
                translated.value = 0;
                error.value = '';
            }
        }
    );

    function start(): void {
        running.value = true;
        stopRequested.value = false;
        total.value = null;
        translated.value = 0;
        error.value = '';
        translateBatch(0);
    }

    function translateBatch(afterId: number): void {
        router.post(
            props.route ?? '',
            { locale: locale.value, after_id: afterId },
            {
                preserveScroll: true,
                preserveState: true,
                only: ['ai'],
                onSuccess: (page) => {
                    const batch = page.flash?.locale_translation as LocaleTranslationBatch | undefined;

                    if (!batch) {
                        finish('Translation stopped: the server returned no progress.');

                        return;
                    }

                    total.value ??= batch.total;
                    translated.value += batch.translated;

                    if (batch.failure) {
                        finish(`Stopped: ${batch.failure}`);
                    } else if (batch.next_after_id !== null && !stopRequested.value) {
                        translateBatch(batch.next_after_id);
                    } else {
                        finish();
                    }
                },
                onError: (errors) => {
                    finish(firstError(errors, 'AI translation failed.'));
                },
            }
        );
    }

    function finish(failure = ''): void {
        running.value = false;
        error.value = failure;

        const noun = translated.value === 1 ? 'value' : 'values';
        const summary = `AI translated ${translated.value} missing ${noun} in ${locale.value} and saved them as drafts.`;

        if (translated.value > 0 || failure === '') {
            emit('done', total.value === 0 ? `No missing values to translate in ${locale.value}.` : summary);
        }
    }
</script>

<template>
    <SlidePanel
        :open="open"
        title="AI translate missing values"
        @close="!running && emit('close')"
    >
        <FormField
            id="locale-translation-locale"
            label="Locale"
        >
            <Select
                id="locale-translation-locale"
                v-model="locale"
                :options="localeOptions"
                :disabled="running"
                data-test="locale-translation-locale"
            />
        </FormField>
        <p class="text-muted-foreground mt-3 text-sm">
            Values that are missing or flagged in this locale are translated from the base wording. Deliberately blank
            values and keys that follow the default language are left unchanged. Translations are saved as drafts for
            review; Publish makes them live.
        </p>
        <div
            v-if="total !== null"
            class="mt-4 space-y-1.5"
        >
            <div class="bg-muted h-2 overflow-hidden rounded-full">
                <div
                    class="bg-primary h-full transition-all"
                    :style="{ width: `${progress}%` }"
                />
            </div>
            <p
                class="text-muted-foreground text-xs tabular-nums"
                data-test="locale-translation-progress"
            >
                {{ translated }} of {{ total }} missing values translated
            </p>
        </div>
        <p
            v-if="error"
            role="alert"
            class="text-destructive mt-3 text-sm"
        >
            {{ error }}
        </p>
        <template #footer>
            <Button
                v-if="running"
                variant="outline"
                data-test="locale-translation-stop"
                :disabled="stopRequested"
                @click="stopRequested = true"
                >{{ stopRequested ? 'Stopping…' : 'Stop' }}</Button
            >
            <Button
                v-else
                data-test="locale-translation-start"
                :disabled="locale === ''"
                @click="start"
                >Translate missing</Button
            >
        </template>
    </SlidePanel>
</template>
