<script lang="ts" setup>
    import { Head, useForm, usePage } from '@inertiajs/vue3';
    import { Check, Loader2 } from '@lucide/vue';
    import { computed, ref, watch } from 'vue';

    import type { SelectOption } from '@/components/ui';
    import { Button, Checkbox, FormField, Input, Select, Textarea } from '@/components/ui';
    import Layout from '@/layouts/Layout.vue';

    defineOptions({
        layout: Layout,
    });

    interface SettingsProps {
        settings: {
            translate_driver: string;
            translate_prompt: string;
            sync_enabled: boolean;
            sync_key: string;
            openai_api_key: string;
            openai_api_key_set: boolean;
            openai_model: string;
            openai_endpoint: string;
            openai_temperature: number;
        };
        drivers: SelectOption[];
    }

    const page = usePage<{
        settings: SettingsProps['settings'];
        drivers: SettingsProps['drivers'];
    }>();
    const settings = computed(() => page.props.settings);
    const drivers = computed(() => page.props.drivers);

    const form = useForm({
        translate_driver: settings.value.translate_driver,
        translate_prompt: settings.value.translate_prompt,
        sync_enabled: settings.value.sync_enabled,
        openai_api_key: '',
        openai_model: settings.value.openai_model,
        openai_endpoint: settings.value.openai_endpoint,
        openai_temperature: settings.value.openai_temperature,
    });

    const syncKey = ref(settings.value.sync_key);
    const showApiKeyInput = ref(false);
    const showSuccess = ref(false);

    watch(
        () => page.props.settings,
        (newSettings) => {
            form.translate_driver = newSettings.translate_driver;
            form.translate_prompt = newSettings.translate_prompt;
            form.sync_enabled = newSettings.sync_enabled;
            form.openai_model = newSettings.openai_model;
            form.openai_endpoint = newSettings.openai_endpoint;
            form.openai_temperature = newSettings.openai_temperature;
        }
    );

    function submitForm(): void {
        form.post('/vox/settings', {
            preserveScroll: true,
            onSuccess: () => {
                showSuccess.value = true;
                showApiKeyInput.value = false;
                form.openai_api_key = '';
                setTimeout(() => {
                    showSuccess.value = false;
                }, 3000);
            },
        });
    }

    const isOpenAiDriver = computed(() => form.translate_driver === 'openai');
</script>

<template>
    <div class="space-y-8">
        <Head title="Settings" />

        <!-- Page Header -->
        <div>
            <h1 class="text-2xl font-semibold tracking-tight">Settings</h1>
            <p class="text-muted-foreground mt-1 text-sm">Configure translation drivers, prompts, and sync options.</p>
        </div>

        <form
            class="space-y-8"
            @submit.prevent="submitForm"
        >
            <!-- Translation Driver Section -->
            <section class="bg-card rounded-xl border p-6">
                <h2 class="text-lg font-semibold">Translation Driver</h2>
                <p class="text-muted-foreground mt-1 text-sm">Select and configure the AI translation service.</p>

                <div class="mt-6 grid gap-6 sm:grid-cols-2">
                    <FormField
                        id="translate_driver"
                        :error="form.errors.translate_driver"
                        description="The translation service to use."
                        label="Driver"
                    >
                        <Select
                            id="translate_driver"
                            v-model="form.translate_driver"
                            :options="drivers"
                            placeholder="Select a driver"
                        />
                    </FormField>
                </div>

                <!-- OpenAI Settings -->
                <div
                    v-if="isOpenAiDriver"
                    class="mt-6 space-y-6 border-t pt-6"
                >
                    <h3 class="text-sm font-medium">OpenAI Configuration</h3>

                    <div class="grid gap-6 sm:grid-cols-2">
                        <FormField
                            id="openai_api_key"
                            :description="
                                settings.openai_api_key_set
                                    ? 'API key is configured. Enter a new key to change it.'
                                    : 'Your OpenAI API key.'
                            "
                            :error="form.errors.openai_api_key"
                            label="API Key"
                        >
                            <div
                                v-if="settings.openai_api_key_set && !showApiKeyInput"
                                class="flex items-center gap-2"
                            >
                                <Input
                                    :model-value="settings.openai_api_key"
                                    class="font-mono"
                                    disabled
                                />
                                <Button
                                    size="sm"
                                    type="button"
                                    variant="outline"
                                    @click="showApiKeyInput = true"
                                >
                                    Change
                                </Button>
                            </div>
                            <Input
                                v-else
                                id="openai_api_key"
                                v-model="form.openai_api_key"
                                placeholder="sk-..."
                                type="password"
                            />
                        </FormField>

                        <FormField
                            id="openai_model"
                            :error="form.errors.openai_model"
                            description="The OpenAI model to use for translations."
                            label="Model"
                        >
                            <Input
                                id="openai_model"
                                v-model="form.openai_model"
                                placeholder="gpt-4o-mini"
                            />
                        </FormField>

                        <FormField
                            id="openai_endpoint"
                            :error="form.errors.openai_endpoint"
                            description="The OpenAI API endpoint URL."
                            label="Endpoint"
                        >
                            <Input
                                id="openai_endpoint"
                                v-model="form.openai_endpoint"
                                placeholder="https://api.openai.com/v1/chat/completions"
                            />
                        </FormField>

                        <FormField
                            id="openai_temperature"
                            :error="form.errors.openai_temperature"
                            description="Controls randomness (0-2). Lower is more deterministic."
                            label="Temperature"
                        >
                            <Input
                                id="openai_temperature"
                                v-model="form.openai_temperature"
                                max="2"
                                min="0"
                                step="0.1"
                                type="number"
                            />
                        </FormField>
                    </div>
                </div>
            </section>

            <!-- Translation Prompt Section -->
            <section class="bg-card rounded-xl border p-6">
                <h2 class="text-lg font-semibold">Translation Prompt</h2>
                <p class="text-muted-foreground mt-1 text-sm">
                    Customize the system prompt sent to the AI for translations.
                </p>

                <div class="mt-6">
                    <FormField
                        id="translate_prompt"
                        :error="form.errors.translate_prompt"
                        description="Use :source and :target placeholders for locale names."
                        label="System Prompt"
                    >
                        <Textarea
                            id="translate_prompt"
                            v-model="form.translate_prompt"
                            :rows="4"
                            placeholder="You are a professional translator..."
                        />
                    </FormField>
                </div>
            </section>

            <!-- Sync Section -->
            <section class="bg-card rounded-xl border p-6">
                <h2 class="text-lg font-semibold">Sync Configuration</h2>
                <p class="text-muted-foreground mt-1 text-sm">Configure remote sync authentication.</p>

                <div class="mt-6 space-y-6">
                    <FormField
                        id="sync_enabled"
                        description="Whether this environment can receive remote sync requests."
                        label="Remote sync enabled"
                    >
                        <Checkbox
                            id="sync_enabled"
                            v-model="form.sync_enabled"
                        />
                    </FormField>

                    <FormField
                        v-if="form.sync_enabled"
                        id="sync_key"
                        description="Secret key for authenticating remote sync requests."
                        label="Sync Key"
                    >
                        <Input
                            id="sync_key"
                            v-model="syncKey"
                            copyable
                            placeholder="Enter sync key..."
                            readonly
                            type="text"
                        />
                    </FormField>
                </div>
            </section>

            <!-- Submit Button -->
            <div class="flex items-center gap-4">
                <Button
                    :disabled="form.processing"
                    type="submit"
                >
                    <Loader2
                        v-if="form.processing"
                        class="size-4 animate-spin"
                    />
                    <span v-else>Save Settings</span>
                </Button>
                <Transition
                    enter-active-class="transition-opacity duration-200"
                    enter-from-class="opacity-0"
                    leave-active-class="transition-opacity duration-200"
                    leave-to-class="opacity-0"
                >
                    <span
                        v-if="showSuccess"
                        class="flex items-center gap-1.5 text-sm text-emerald-600"
                    >
                        <Check class="size-4" />
                        Settings saved
                    </span>
                </Transition>
            </div>
        </form>
    </div>
</template>
