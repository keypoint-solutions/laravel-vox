import { onMounted, onUnmounted, type Ref, ref } from 'vue';

/**
 * Reports when the tracked element has scrolled out of view, so a compact sticky header can take over.
 */
export function useCompactHeader(element: Ref<HTMLElement | null>) {
    const isCompactMode = ref(false);
    let observer: ResizeObserver | null = null;

    function update(): void {
        isCompactMode.value = (element.value?.getBoundingClientRect().bottom ?? 1) <= 0;
    }

    onMounted(() => {
        window.addEventListener('scroll', update, { passive: true });
        window.addEventListener('resize', update);
        observer = new ResizeObserver(update);
        update();

        if (element.value) {
            observer.observe(element.value);
        }
    });

    onUnmounted(() => {
        window.removeEventListener('scroll', update);
        window.removeEventListener('resize', update);
        observer?.disconnect();
    });

    return { isCompactMode };
}
