<script lang="ts" setup>
    import { computed, ref } from 'vue';

    import { copyTextToClipboard } from '@/lib/clipboard';
    import { cn } from '@/lib/utils';

    import Tooltip from './Tooltip.vue';

    const props = defineProps<{
        modelValue?: string | number;
        type?: string;
        placeholder?: string;
        disabled?: boolean;
        class?: string;
        id?: string;
        copyable?: boolean;
        readonly?: boolean;
    }>();

    const emit = defineEmits<{
        'update:modelValue': [value: string];
    }>();

    const inputValue = computed({
        get: () => props.modelValue ?? '',
        set: (value) => emit('update:modelValue', String(value)),
    });

    const copied = ref(false);

    async function copyToClipboard(): Promise<void> {
        if (await copyTextToClipboard(String(inputValue.value))) {
            showCopiedFeedback();
        }
    }

    function showCopiedFeedback(): void {
        copied.value = true;
        setTimeout(() => {
            copied.value = false;
        }, 2000);
    }
</script>

<template>
    <div
        v-if="copyable"
        class="relative"
    >
        <input
            :id="id"
            v-model="inputValue"
            :class="
                cn(
                    'border-input bg-background ring-offset-background flex h-10 w-full rounded-lg border px-3 py-2 pr-10 text-sm',
                    'file:border-0 file:bg-transparent file:text-sm file:font-medium',
                    'placeholder:text-muted-foreground',
                    'focus-visible:ring-ring focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none',
                    'disabled:cursor-not-allowed disabled:opacity-50',
                    $props.class
                )
            "
            :disabled="disabled"
            :placeholder="placeholder"
            :readonly="readonly"
            :type="type ?? 'text'"
        />
        <span class="absolute top-1/2 right-2 z-10 -translate-y-1/2">
            <Tooltip :text="copied ? 'Copied!' : 'Copy to clipboard'">
                <button
                    :aria-label="copied ? 'Copied!' : 'Copy to clipboard'"
                    :disabled="disabled"
                    class="text-muted-foreground hover:text-foreground cursor-pointer p-1 transition-colors"
                    type="button"
                    @click.stop.prevent="copyToClipboard"
                >
                    <svg
                        v-if="!copied"
                        class="h-4 w-4"
                        fill="none"
                        stroke="currentColor"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        stroke-width="2"
                        viewBox="0 0 24 24"
                        xmlns="http://www.w3.org/2000/svg"
                    >
                        <rect
                            height="14"
                            rx="2"
                            ry="2"
                            width="14"
                            x="8"
                            y="8"
                        />
                        <path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2" />
                    </svg>
                    <svg
                        v-else
                        class="h-4 w-4 text-green-500"
                        fill="none"
                        stroke="currentColor"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        stroke-width="2"
                        viewBox="0 0 24 24"
                        xmlns="http://www.w3.org/2000/svg"
                    >
                        <polyline points="20 6 9 17 4 12" />
                    </svg>
                </button>
            </Tooltip>
        </span>
    </div>
    <input
        v-else
        :id="id"
        v-model="inputValue"
        :class="
            cn(
                'border-input bg-background ring-offset-background flex h-10 w-full rounded-lg border px-3 py-2 text-sm',
                'file:border-0 file:bg-transparent file:text-sm file:font-medium',
                'placeholder:text-muted-foreground',
                'focus-visible:ring-ring focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none',
                'disabled:cursor-not-allowed disabled:opacity-50',
                $props.class
            )
        "
        :disabled="disabled"
        :placeholder="placeholder"
        :readonly="readonly"
        :type="type ?? 'text'"
    />
</template>
