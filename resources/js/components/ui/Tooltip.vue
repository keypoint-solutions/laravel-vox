<script setup lang="ts">
    withDefaults(
        defineProps<{
            text: string;
            position?: 'top' | 'bottom';
        }>(),
        {
            position: 'top',
        }
    );
</script>

<template>
    <span class="group/tooltip relative inline-flex">
        <slot />
        <span
            role="tooltip"
            :class="[
                'pointer-events-none absolute left-1/2 z-50 w-max max-w-56 -translate-x-1/2 rounded-md bg-slate-950 px-2 py-1 text-center text-xs font-medium text-white opacity-0 shadow-lg transition-opacity',
                'group-focus-within/tooltip:opacity-100 group-hover/tooltip:opacity-100',
                position === 'top' ? 'bottom-full mb-2' : 'top-full mt-2',
            ]"
        >
            {{ text }}
        </span>
    </span>
</template>
