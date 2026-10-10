<script setup lang="ts">
    import { Check } from '@lucide/vue';

    defineProps<{
        tone: 'error' | 'success';
    }>();
</script>

<template>
    <div
        v-if="tone === 'error'"
        role="alert"
        class="text-destructive border-destructive/40 bg-destructive/10 rounded-lg border p-3 text-sm"
    >
        <slot />
    </div>
    <div
        v-else
        role="status"
        aria-live="polite"
        class="flex items-center gap-2 rounded-lg border border-emerald-500/30 bg-emerald-500/10 p-3 text-sm text-emerald-600"
    >
        <Check class="size-4 shrink-0" />
        <slot />
    </div>
</template>
