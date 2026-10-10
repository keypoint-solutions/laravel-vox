<script setup lang="ts">
    import { router } from '@inertiajs/vue3';
    import { ref, watch } from 'vue';

    import { Button, Select } from '@/components/ui';
    import { useVoxRoutes } from '@/composables/useVoxRoutes';
    import { firstError } from '@/lib/inertia';

    const props = defineProps<{
        locale: string;
        scope: 'locale' | 'group' | 'key';
        group?: string | null;
        translationId?: number;
        selection: string;
        published: string;
        baseLocale: string;
    }>();
    const routes = useVoxRoutes();
    const mode = ref(props.selection);
    const saving = ref(false);
    const error = ref('');
    const message = ref('');
    watch(
        () => props.selection,
        (value) => {
            mode.value = value;
        }
    );
    const options = [
        { value: 'inherit', label: 'Inherit setting' },
        { value: 'translated', label: 'Use own translation' },
        { value: 'default', label: 'Use default language' },
    ];
    function save(): void {
        saving.value = true;
        error.value = '';
        message.value = '';
        router.post(
            routes.value?.manage_fallback ?? '',
            {
                locale: props.locale,
                scope: props.scope,
                group: props.group,
                translation_id: props.translationId,
                mode: mode.value,
            },
            {
                preserveScroll: true,
                onError: (errors) => {
                    error.value = firstError(errors, 'Unable to save fallback choice.');
                },
                onSuccess: () => {
                    message.value = 'Saved. Publish to apply.';
                },
                onFinish: () => {
                    saving.value = false;
                },
            }
        );
    }
</script>

<template>
    <div class="space-y-2">
        <div class="flex flex-wrap items-center gap-2">
            <Select
                v-model="mode"
                :options="options"
                :aria-label="`${locale} ${scope} fallback`"
                class="min-w-48 flex-1"
            />
            <Button
                size="sm"
                variant="outline"
                :disabled="saving || mode === selection"
                @click="save"
            >
                {{ saving ? 'Saving…' : 'Save for publish' }}
            </Button>
        </div>
        <p class="text-muted-foreground text-xs">
            Default language: {{ baseLocale }}. Existing wording is preserved.
            <span v-if="selection !== published">Choice pending publication.</span>
        </p>
        <p
            v-if="message"
            role="status"
            class="text-xs text-emerald-600"
        >
            {{ message }}
        </p>
        <p
            v-if="error"
            role="alert"
            class="text-destructive text-xs"
        >
            {{ error }}
        </p>
    </div>
</template>
