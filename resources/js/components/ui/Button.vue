<script setup lang="ts">
    import { computed } from 'vue';

    import { cn } from '@/lib/utils';

    type ButtonVariant = 'default' | 'destructive' | 'outline' | 'secondary' | 'ghost' | 'link';
    type ButtonSize = 'default' | 'sm' | 'lg' | 'icon';

    const props = defineProps<{
        variant?: ButtonVariant;
        size?: ButtonSize;
        disabled?: boolean;
        type?: 'button' | 'submit' | 'reset';
        class?: string;
    }>();

    const variantClasses: Record<ButtonVariant, string> = {
        default: 'bg-primary text-primary-foreground hover:bg-primary/90',
        destructive: 'bg-destructive text-destructive-foreground hover:bg-destructive/90',
        outline: 'border border-input bg-background hover:bg-accent hover:text-accent-foreground',
        secondary: 'bg-secondary text-secondary-foreground hover:bg-secondary/80',
        ghost: 'hover:bg-accent hover:text-accent-foreground',
        link: 'text-primary underline-offset-4 hover:underline',
    };

    const sizeClasses: Record<ButtonSize, string> = {
        default: 'h-10 px-4 py-2',
        sm: 'h-9 rounded-md px-3',
        lg: 'h-11 rounded-md px-8',
        icon: 'size-10',
    };

    const buttonClasses = computed(() =>
        cn(
            'inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-lg text-sm font-medium ring-offset-background transition-colors',
            'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2',
            'disabled:pointer-events-none disabled:opacity-50',
            variantClasses[props.variant ?? 'default'],
            sizeClasses[props.size ?? 'default'],
            props.class
        )
    );
</script>

<template>
    <button
        :type="type ?? 'button'"
        :disabled="disabled"
        :class="buttonClasses"
    >
        <slot />
    </button>
</template>
