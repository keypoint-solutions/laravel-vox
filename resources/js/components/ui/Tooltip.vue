<script setup lang="ts">
    withDefaults(
        defineProps<{
            text: string;
            position?: 'top' | 'bottom';
            align?: 'start' | 'center' | 'end';
        }>(),
        {
            position: 'top',
            align: 'center',
        }
    );
</script>

<template>
    <span class="group/tooltip relative inline-flex">
        <slot />
        <span
            role="tooltip"
            :class="[
                'pointer-events-none absolute z-50 w-max max-w-56 rounded-md bg-slate-950 px-2 py-1 text-center text-xs font-medium text-white opacity-0 shadow-lg transition-opacity',
                'group-focus-within/tooltip:opacity-100 group-hover/tooltip:opacity-100',
                position === 'top' ? 'bottom-full mb-2' : 'top-full mt-2',
                align === 'start' && 'left-0',
                align === 'center' && 'left-1/2 -translate-x-1/2',
                align === 'end' && 'right-0',
            ]"
        >
            {{ text }}
        </span>
    </span>
</template>
