<script setup lang="ts">
    import { Search, X } from '@lucide/vue';
    import { computed } from 'vue';

    import { cn } from '@/lib/utils';

    const props = defineProps<{
        modelValue: string;
        placeholder?: string;
        class?: string;
    }>();

    const emit = defineEmits<{
        'update:modelValue': [value: string];
        search: [];
        clear: [];
    }>();

    const inputValue = computed({
        get: () => props.modelValue,
        set: (value) => emit('update:modelValue', value),
    });

    function handleClear() {
        emit('update:modelValue', '');
        emit('clear');
    }

    function handleKeydown(event: KeyboardEvent) {
        if (event.key === 'Enter') {
            emit('search');
        }
        if (event.key === 'Escape' && inputValue.value) {
            handleClear();
        }
    }
</script>

<template>
    <div :class="cn('relative', $props.class)">
        <Search class="text-muted-foreground pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2" />
        <input
            v-model="inputValue"
            type="text"
            :placeholder="placeholder ?? 'Search...'"
            class="border-input bg-background ring-offset-background placeholder:text-muted-foreground focus-visible:ring-ring flex h-10 w-full rounded-lg border py-2 pr-9 pl-9 text-sm focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50"
            @keydown="handleKeydown"
        />
        <button
            v-if="inputValue"
            type="button"
            class="text-muted-foreground hover:text-foreground absolute top-1/2 right-2 -translate-y-1/2 rounded p-1 transition-colors"
            @click="handleClear"
        >
            <X class="size-4" />
        </button>
    </div>
</template>
