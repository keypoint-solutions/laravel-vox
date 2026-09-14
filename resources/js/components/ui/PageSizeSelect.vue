<script setup lang="ts">
    defineProps<{ modelValue: number }>();
    const emit = defineEmits<{ 'update:modelValue': [value: number] }>();
</script>

<template>
    <label class="text-muted-foreground flex items-center gap-2 text-xs">
        Per page
        <select
            aria-label="Records per page"
            class="bg-background text-foreground rounded-lg border px-2 py-1.5 text-sm"
            :value="modelValue"
            @change="emit('update:modelValue', Number(($event.target as HTMLSelectElement).value))"
        >
            <option
                v-for="size in [25, 50, 100]"
                :key="size"
                :value="size"
            >
                {{ size }}
            </option>
        </select>
    </label>
</template>
