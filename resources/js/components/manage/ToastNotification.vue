<script lang="ts" setup>
    import { Check, CircleAlert, X } from '@lucide/vue';

    import { cn } from '@/lib/utils';
    import type { ToastTone } from '@/types/manage';

    defineProps<{
        toast: { message: string; tone: ToastTone } | null;
    }>();

    const emit = defineEmits<{
        dismiss: [];
    }>();
</script>

<template>
    <Transition
        enter-active-class="transition duration-200 ease-out"
        enter-from-class="translate-y-2 opacity-0"
        enter-to-class="translate-y-0 opacity-100"
        leave-active-class="transition duration-150 ease-in"
        leave-from-class="translate-y-0 opacity-100"
        leave-to-class="translate-y-2 opacity-0"
    >
        <div
            v-if="toast"
            data-test="success-toast"
            :role="toast.tone === 'error' ? 'alert' : 'status'"
            :aria-live="toast.tone === 'error' ? 'assertive' : 'polite'"
            :class="
                cn(
                    'bg-card fixed top-4 right-4 z-[70] flex max-w-[calc(100vw-2rem)] items-center gap-3 rounded-lg border px-4 py-3 text-sm shadow-lg sm:max-w-md',
                    toast.tone === 'error'
                        ? 'border-destructive/40 text-destructive'
                        : 'border-emerald-500/40 text-emerald-600'
                )
            "
        >
            <CircleAlert
                v-if="toast.tone === 'error'"
                class="size-4 shrink-0"
            />
            <Check
                v-else
                class="size-4 shrink-0"
            />
            <span class="min-w-0 flex-1">{{ toast.message }}</span>
            <button
                aria-label="Dismiss notification"
                class="rounded-sm opacity-70 transition-opacity hover:opacity-100 focus-visible:outline-2 focus-visible:outline-offset-2"
                type="button"
                @click="emit('dismiss')"
            >
                <X class="size-4" />
            </button>
        </div>
    </Transition>
</template>
