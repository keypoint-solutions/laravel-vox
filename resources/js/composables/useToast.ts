import { onUnmounted, ref } from 'vue';

import type { ToastTone } from '@/types/manage';

export function useToast(duration = 5000) {
    const toast = ref<{ message: string; tone: ToastTone } | null>(null);
    let timer: ReturnType<typeof setTimeout> | null = null;

    function dismissToast(): void {
        if (timer) {
            clearTimeout(timer);
            timer = null;
        }

        toast.value = null;
    }

    function showToast(message: string, tone: ToastTone = 'success'): void {
        dismissToast();
        toast.value = { message, tone };
        timer = setTimeout(() => {
            toast.value = null;
            timer = null;
        }, duration);
    }

    onUnmounted(() => {
        if (timer) {
            clearTimeout(timer);
        }
    });

    return { toast, showToast, dismissToast };
}
