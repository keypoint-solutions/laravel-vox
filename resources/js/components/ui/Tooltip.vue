<script setup lang="ts">
    import { nextTick, onBeforeUnmount, ref } from 'vue';

    const props = withDefaults(
        defineProps<{
            text: string;
            position?: 'top' | 'bottom';
            align?: 'start' | 'center' | 'end';
        }>(),
        { position: 'top', align: 'center' }
    );
    const trigger = ref<HTMLElement>();
    const tooltip = ref<HTMLElement>();
    const visible = ref(false);
    const style = ref({ left: '0px', top: '0px', visibility: 'hidden' as 'hidden' | 'visible' });

    async function show(): Promise<void> {
        visible.value = true;
        style.value.visibility = 'hidden';
        await nextTick();
        if (!trigger.value || !tooltip.value || !visible.value) {
            return;
        }
        const anchor = trigger.value.getBoundingClientRect();
        const bubble = tooltip.value.getBoundingClientRect();
        const margin = 8;
        let left =
            props.align === 'start'
                ? anchor.left
                : props.align === 'end'
                  ? anchor.right - bubble.width
                  : anchor.left + (anchor.width - bubble.width) / 2;
        left = Math.max(margin, Math.min(left, window.innerWidth - bubble.width - margin));
        let top = props.position === 'top' ? anchor.top - bubble.height - margin : anchor.bottom + margin;
        if (top < margin) {
            top = anchor.bottom + margin;
        } else if (top + bubble.height > window.innerHeight - margin) {
            top = anchor.top - bubble.height - margin;
        }
        style.value = { left: `${left}px`, top: `${Math.max(margin, top)}px`, visibility: 'visible' };
        window.addEventListener('scroll', hide, true);
        window.addEventListener('resize', hide);
    }

    function hide(): void {
        visible.value = false;
        window.removeEventListener('scroll', hide, true);
        window.removeEventListener('resize', hide);
    }

    onBeforeUnmount(hide);
</script>

<template>
    <span
        ref="trigger"
        class="inline-flex"
        @mouseenter="show"
        @mouseleave="hide"
        @focusin="show"
        @focusout="hide"
    >
        <slot />
        <Teleport to="body">
            <span
                v-if="visible"
                ref="tooltip"
                role="tooltip"
                class="pointer-events-none fixed z-[100] w-max max-w-[min(14rem,calc(100vw-1rem))] rounded-md bg-slate-950 px-2 py-1 text-center text-xs font-medium break-words text-white shadow-lg"
                :style="style"
                >{{ text }}</span
            >
        </Teleport>
    </span>
</template>
