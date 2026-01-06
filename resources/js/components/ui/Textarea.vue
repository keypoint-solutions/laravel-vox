<script lang="ts" setup>
    import { computed } from 'vue';

    import { cn } from '@/lib/utils';

    const props = defineProps<{
        modelValue?: string;
        placeholder?: string;
        disabled?: boolean;
        rows?: number;
        class?: string;
        id?: string;
    }>();

    const emit = defineEmits<{
        'update:modelValue': [value: string];
    }>();

    const textareaValue = computed({
        get: () => props.modelValue ?? '',
        set: (value) => emit('update:modelValue', value),
    });
</script>

<template>
    <textarea
        :id="id"
        v-model="textareaValue"
        :class="
            cn(
                'border-input bg-background ring-offset-background flex min-h-20 w-full rounded-lg border px-3 py-2 text-sm',
                'placeholder:text-muted-foreground',
                'focus-visible:ring-ring focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none',
                'disabled:cursor-not-allowed disabled:opacity-50',
                $props.class
            )
        "
        :disabled="disabled"
        :placeholder="placeholder"
        :rows="rows ?? 4"
    />
</template>
