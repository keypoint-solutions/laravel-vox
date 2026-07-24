<script lang="ts" setup>
    import { Check } from '@lucide/vue';
    import { computed } from 'vue';

    import { cn } from '@/lib/utils';

    const props = defineProps<{
        modelValue?: boolean;
        disabled?: boolean;
        class?: string;
        id?: string;
    }>();

    const emit = defineEmits<{
        'update:modelValue': [value: boolean];
    }>();

    const checked = computed({
        get: () => props.modelValue ?? false,
        set: (value) => emit('update:modelValue', value),
    });
</script>

<template>
    <button
        :id="id"
        :aria-checked="checked"
        :class="
            cn(
                'peer border-primary ring-offset-background ml-2 size-4 shrink-0 rounded border',
                'focus-visible:ring-ring focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none',
                'disabled:cursor-not-allowed disabled:opacity-50',
                checked ? 'bg-primary text-primary-foreground' : 'bg-background',
                $props.class
            )
        "
        :disabled="disabled"
        role="checkbox"
        type="button"
        @click="checked = !checked"
    >
        <span :class="cn('flex items-center justify-center text-current', checked ? 'opacity-100' : 'opacity-0')">
            <Check class="size-3" />
        </span>
    </button>
</template>
