<script setup lang="ts">
    import { cn } from '@/lib/utils';

    import Label from './Label.vue';

    defineProps<{
        label: string;
        id?: string;
        description?: string;
        error?: string;
        class?: string;
    }>();
</script>

<template>
    <div :class="cn('space-y-2', $props.class)">
        <Label :for="id">{{ label }}</Label>
        <slot />
        <p
            v-if="description && !error"
            class="text-muted-foreground text-sm"
        >
            {{ description }}
        </p>
        <p
            v-if="error"
            class="text-destructive text-sm"
        >
            {{ error }}
        </p>
    </div>
</template>
