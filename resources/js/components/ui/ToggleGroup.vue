<script setup lang="ts">
    import { computed } from 'vue';

    import { cn } from '@/lib/utils';

    export interface ToggleOption {
        value: string;
        label: string;
        icon?: object;
    }

    const props = defineProps<{
        modelValue: string;
        options: ToggleOption[];
        class?: string;
        size?: 'sm' | 'default';
    }>();

    const emit = defineEmits<{
        'update:modelValue': [value: string];
    }>();

    const sizeClasses = computed(() => (props.size === 'sm' ? 'text-xs px-2 py-1' : 'text-sm px-3 py-1.5'));
</script>

<template>
    <div
        :class="cn('bg-muted inline-flex items-center gap-0.5 rounded-lg p-1', $props.class)"
        role="group"
    >
        <button
            v-for="option in options"
            :key="option.value"
            type="button"
            :class="
                cn(
                    'inline-flex items-center justify-center gap-1.5 rounded-md font-medium transition-colors',
                    sizeClasses,
                    modelValue === option.value
                        ? 'bg-background text-foreground shadow-sm'
                        : 'text-muted-foreground hover:text-foreground'
                )
            "
            @click="emit('update:modelValue', option.value)"
        >
            <component
                :is="option.icon"
                v-if="option.icon"
                class="size-3.5"
            />
            <span>{{ option.label }}</span>
        </button>
    </div>
</template>
