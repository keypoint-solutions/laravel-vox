<script setup lang="ts">
    import { computed } from 'vue';

    import { cn } from '@/lib/utils';

    type BadgeVariant = 'default' | 'secondary' | 'success' | 'warning' | 'info' | 'destructive' | 'outline';

    const props = defineProps<{
        variant?: BadgeVariant;
        class?: string;
    }>();

    const variantClasses: Record<BadgeVariant, string> = {
        default: 'bg-primary/10 text-primary border-transparent',
        secondary: 'bg-muted text-muted-foreground border-transparent',
        success: 'bg-emerald-500/10 text-emerald-500 border-transparent',
        warning: 'bg-amber-500/10 text-amber-500 border-transparent',
        info: 'bg-blue-500/10 text-blue-500 border-transparent',
        destructive: 'bg-destructive/10 text-destructive border-transparent',
        outline: 'bg-transparent border-border text-foreground',
    };

    const badgeClasses = computed(() =>
        cn(
            'inline-flex items-center gap-1 rounded-md border px-2 py-0.5 text-xs font-medium transition-colors',
            variantClasses[props.variant ?? 'default'],
            props.class
        )
    );
</script>

<template>
    <span :class="badgeClasses">
        <slot />
    </span>
</template>
