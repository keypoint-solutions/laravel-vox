<script setup lang="ts">
    import { X } from 'lucide-vue-next';
    import { onMounted, onUnmounted, watch } from 'vue';

    import { cn } from '@/lib/utils';

    import Button from './Button.vue';

    const props = defineProps<{
        open: boolean;
        title?: string;
        subtitle?: string;
        class?: string;
    }>();

    const emit = defineEmits<{
        close: [];
    }>();

    function handleEscape(event: KeyboardEvent) {
        if (event.key === 'Escape' && props.open) {
            emit('close');
        }
    }

    function lockScroll() {
        document.body.style.overflow = 'hidden';
    }

    function unlockScroll() {
        document.body.style.overflow = '';
    }

    watch(
        () => props.open,
        (isOpen) => {
            if (isOpen) {
                lockScroll();
            } else {
                unlockScroll();
            }
        }
    );

    onMounted(() => {
        document.addEventListener('keydown', handleEscape);
        if (props.open) {
            lockScroll();
        }
    });

    onUnmounted(() => {
        document.removeEventListener('keydown', handleEscape);
        unlockScroll();
    });
</script>

<template>
    <Teleport to="body">
        <!-- Backdrop -->
        <Transition
            enter-active-class="transition-opacity duration-200"
            enter-from-class="opacity-0"
            enter-to-class="opacity-100"
            leave-active-class="transition-opacity duration-200"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
        >
            <div
                v-if="open"
                class="fixed inset-0 z-40 bg-black/40"
                @click="emit('close')"
            />
        </Transition>

        <!-- Panel -->
        <Transition
            enter-active-class="transition-transform duration-200"
            enter-from-class="translate-x-full"
            enter-to-class="translate-x-0"
            leave-active-class="transition-transform duration-200"
            leave-from-class="translate-x-0"
            leave-to-class="translate-x-full"
        >
            <aside
                v-if="open"
                :class="
                    cn(
                        'bg-background fixed inset-y-0 right-0 z-50 flex w-full max-w-xl flex-col border-l shadow-xl',
                        $props.class
                    )
                "
            >
                <!-- Header -->
                <div class="flex items-start justify-between gap-4 border-b p-6">
                    <div class="min-w-0 flex-1">
                        <slot name="header">
                            <p
                                v-if="subtitle"
                                class="text-muted-foreground text-xs tracking-[0.2em] uppercase"
                            >
                                {{ subtitle }}
                            </p>
                            <h2
                                v-if="title"
                                class="mt-1 truncate text-lg font-semibold"
                            >
                                {{ title }}
                            </h2>
                        </slot>
                    </div>
                    <Button
                        size="icon"
                        variant="ghost"
                        class="size-8 shrink-0"
                        @click="emit('close')"
                    >
                        <X class="size-4" />
                    </Button>
                </div>

                <!-- Content -->
                <div class="flex-1 overflow-y-auto p-6">
                    <slot />
                </div>

                <!-- Footer -->
                <div
                    v-if="$slots.footer"
                    class="border-t p-6"
                >
                    <slot name="footer" />
                </div>
            </aside>
        </Transition>
    </Teleport>
</template>
