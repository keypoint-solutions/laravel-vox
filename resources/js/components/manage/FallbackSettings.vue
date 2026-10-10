<script lang="ts" setup>
    import { ref } from 'vue';

    import FallbackChoice from '@/components/FallbackChoice.vue';
    import { Select } from '@/components/ui';
    import type { FallbackRule } from '@/types/manage';

    const props = defineProps<{
        baseLocale: string;
        targetLocales: string[];
        selectedGroup: string | null;
        fallbackRules: FallbackRule[];
    }>();

    const locale = ref('');

    function scopeRule(scope: string, published = false): string {
        const rule = props.fallbackRules.find(
            (rule) =>
                rule.locale === locale.value &&
                rule.scope === scope &&
                (scope === 'locale' ||
                    rule.group === (props.selectedGroup === 'default' ? 'json' : props.selectedGroup))
        );

        return rule?.[published ? 'published_mode' : 'mode'] ?? 'inherit';
    }
</script>

<template>
    <details class="bg-card rounded-xl border p-4">
        <summary class="cursor-pointer text-sm font-semibold">Language and group fallback</summary>
        <div class="mt-4 max-w-xl space-y-4">
            <p class="text-muted-foreground text-sm">
                Use {{ baseLocale }} wording for a language or group. Individual keys can override these choices.
                Publish to apply changes.
            </p>
            <Select
                v-model="locale"
                :options="targetLocales.map((locale) => ({ value: locale, label: locale }))"
                placeholder="Choose language"
                aria-label="Fallback language"
            />
            <template v-if="locale">
                <div class="space-y-2">
                    <p class="text-sm font-medium">Entire language · {{ locale }}</p>
                    <FallbackChoice
                        :key="locale + '-locale'"
                        :locale="locale"
                        scope="locale"
                        :selection="scopeRule('locale')"
                        :published="scopeRule('locale', true)"
                        :base-locale="baseLocale"
                    />
                </div>
                <div
                    v-if="selectedGroup"
                    class="space-y-2 border-t pt-4"
                >
                    <p class="text-sm font-medium">Group · {{ selectedGroup }}</p>
                    <FallbackChoice
                        :key="locale + selectedGroup"
                        :locale="locale"
                        scope="group"
                        :group="selectedGroup === 'default' ? 'json' : selectedGroup"
                        :selection="scopeRule('group')"
                        :published="scopeRule('group', true)"
                        :base-locale="baseLocale"
                    />
                </div>
                <p
                    v-else
                    class="text-muted-foreground text-xs"
                >
                    Select a group above to configure a group override.
                </p>
            </template>
        </div>
    </details>
</template>
