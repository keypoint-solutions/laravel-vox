<script setup lang="ts">
    import { ChevronDown } from '@lucide/vue';
    import { computed } from 'vue';

    import { cn } from '@/lib/utils';

    export interface SelectOption {
        value: string;
        label: string;
    }

    const props = defineProps<{
        modelValue?: string;
        options: SelectOption[];
        placeholder?: string;
        disabled?: boolean;
        class?: string;
        id?: string;
    }>();

    const emit = defineEmits<{
        'update:modelValue': [value: string];
    }>();

    const selectValue = computed({
        get: () => props.modelValue ?? '',
        set: (value) => emit('update:modelValue', value),
    });
</script>

<template>
    <div class="relative">
        <select
            :id="id"
            v-model="selectValue"
            :disabled="disabled"
            :class="
                cn(
                    'border-input bg-background ring-offset-background flex h-10 w-full appearance-none rounded-lg border px-3 py-2 pr-10 text-sm',
                    'focus-visible:ring-ring focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none',
                    'disabled:cursor-not-allowed disabled:opacity-50',
                    $props.class
                )
            "
        >
            <option
                v-if="placeholder"
                value=""
                disabled
            >
                {{ placeholder }}
            </option>
            <option
                v-for="option in options"
                :key="option.value"
                :value="option.value"
            >
                {{ option.label }}
            </option>
        </select>
        <ChevronDown
            class="text-muted-foreground pointer-events-none absolute top-1/2 right-3 size-4 -translate-y-1/2"
        />
    </div>
</template>
