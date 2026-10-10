<script setup lang="ts">
    import { ChevronLeft, ChevronRight } from '@lucide/vue';

    import Button from './Button.vue';
    import Tooltip from './Tooltip.vue';

    /**
     * Previous and next page buttons. Renders no wrapper, so the parent decides the layout;
     * the default slot is placed between the two buttons.
     */
    defineProps<{
        currentPage: number;
        lastPage: number;
        variant?: 'icon' | 'text';
        size?: 'sm';
        buttonClass?: string;
        disabled?: boolean;
    }>();

    const emit = defineEmits<{
        change: [page: number];
    }>();
</script>

<template>
    <template v-if="variant === 'text'">
        <Button
            :size="size"
            variant="outline"
            :disabled="disabled || currentPage <= 1"
            @click="emit('change', currentPage - 1)"
            >Previous</Button
        >
        <slot />
        <Button
            :size="size"
            variant="outline"
            :disabled="disabled || currentPage >= lastPage"
            @click="emit('change', currentPage + 1)"
            >Next</Button
        >
    </template>
    <template v-else>
        <Tooltip text="Previous page">
            <Button
                aria-label="Previous page"
                :class="buttonClass"
                :disabled="disabled || currentPage <= 1"
                size="icon"
                variant="ghost"
                @click="emit('change', currentPage - 1)"
            >
                <ChevronLeft class="size-4" />
            </Button>
        </Tooltip>
        <slot />
        <Tooltip text="Next page">
            <Button
                aria-label="Next page"
                :class="buttonClass"
                :disabled="disabled || currentPage >= lastPage"
                size="icon"
                variant="ghost"
                @click="emit('change', currentPage + 1)"
            >
                <ChevronRight class="size-4" />
            </Button>
        </Tooltip>
    </template>
</template>
