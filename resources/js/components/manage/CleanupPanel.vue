<script lang="ts" setup>
    import { router } from '@inertiajs/vue3';
    import { computed, ref, watch } from 'vue';

    import { Button, FormField, Input, SlidePanel } from '@/components/ui';
    import { firstError } from '@/lib/inertia';
    import type { CleanupAction, TranslationItem } from '@/types/manage';

    const props = defineProps<{
        rows: TranslationItem[];
        action: CleanupAction;
        route: string | undefined;
    }>();

    const emit = defineEmits<{
        close: [];
        done: [];
    }>();

    const busy = defineModel<boolean>('busy', { default: false });

    const confirmation = ref('');
    const error = ref('');
    const title = computed(() => ({ delete: 'Delete keys', restore: 'Cancel deletion' })[props.action]);

    watch(
        () => props.rows,
        () => {
            confirmation.value = '';
            error.value = '';
        }
    );

    function submit(): void {
        busy.value = true;
        router.post(
            props.route ?? '',
            {
                ids: props.rows.map((row) => row.id),
                action: props.action,
                confirmation: props.action === 'restore' ? confirmation.value : 'CONFIRM',
            },
            {
                preserveScroll: true,
                onSuccess: () => emit('done'),
                onError: (errors) => {
                    error.value = firstError(errors, 'Unable to update the selection.');
                },
                onFinish: () => {
                    busy.value = false;
                },
            }
        );
    }
</script>

<template>
    <SlidePanel
        :open="rows.length > 0"
        :title="title"
        @close="!busy && emit('close')"
    >
        <p>
            {{ rows.length }} keys and {{ rows.reduce((count, row) => count + row.values_count, 0) }} locale values
            selected.
        </p>
        <p class="mt-3 font-semibold">
            {{
                action === 'delete'
                    ? 'Keys will be marked for deletion in Vox. Language files remain unchanged until Publish.'
                    : 'Published language files will not be changed.'
            }}
        </p>
        <p
            v-if="action === 'delete'"
            class="mt-3"
        >
            Publish will permanently remove the selected keys, their values, and related reconciliation records. All
            locale wording will be lost. Parse may rediscover used keys, but cannot recover their previous translations.
            Dynamically constructed keys may not reappear.
        </p>
        <p
            v-else
            class="mt-3"
        >
            These keys will return to normal management.
        </p>
        <ul class="my-4 space-y-1">
            <li
                v-for="row in rows"
                :key="row.id"
                class="font-mono text-xs break-all"
            >
                {{ row.display_key }}
            </li>
        </ul>
        <FormField
            v-if="action === 'restore'"
            label="Type CONFIRM to continue"
            ><Input
                id="cleanup-confirmation"
                v-model="confirmation"
        /></FormField>
        <p
            v-if="error"
            role="alert"
            class="text-destructive mt-3"
        >
            {{ error }}
        </p>
        <template #footer
            ><Button
                :disabled="busy || (action === 'restore' && confirmation !== 'CONFIRM')"
                data-test="confirm-cleanup"
                @click="submit"
                >{{ busy ? 'Working…' : title }}</Button
            ></template
        >
    </SlidePanel>
</template>
