<script lang="ts" setup>
import { trans } from 'laravel-vue-i18n';
import { computed, ref } from 'vue';

type TestAppUser = {
    id: number;
    name: string;
    email: string;
};

const authUser = ref<TestAppUser | null>(
    (window as Window & { voxTestAppUser?: TestAppUser | null })
        .voxTestAppUser ?? null,
);

const authLabel = computed(() =>
    authUser.value
        ? `${authUser.value.name} (${authUser.value.email})`
        : 'Guest',
);

const dynamicLabels = computed(() => {
    return {
        label1: trans('frontend.dynamicLabels.labels.Dynamic Label 1'),
        label2: trans('frontend.dynamicLabels.labels.Dynamic Label 2'),
    };
});

const dynamicLabels2 = computed(() => {
    return {
        label1: trans('frontend.dynamicLabels2.labels.Dynamic Label 1'),
        label2: trans('frontend.dynamicLabels2.labels.Dynamic Label 2'),
    };
});
</script>

<template>
    <section class="space-y-6">
        <p class="text-xs tracking-[0.2em] text-muted-foreground uppercase">
            Welcome
        </p>
        <h1 class="text-2xl font-semibold">Laravel Vox Test App</h1>
        <p class="text-sm text-muted-foreground">
            This is a minimal Vue Router SPA used to exercise the package in a
            lightweight environment.
        </p>
        <div class="flex flex-wrap gap-3">
            <RouterLink
                class="inline-flex items-center gap-2 rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground transition hover:bg-primary/90"
                to="/test-one"
            >
                Go to Test One
            </RouterLink>
            <RouterLink
                class="inline-flex items-center gap-2 rounded-md border border-input px-4 py-2 text-sm font-medium text-foreground transition hover:bg-muted"
                to="/test-two"
            >
                Go to Test Two
            </RouterLink>
        </div>

        <section class="rounded-xl border bg-card p-6">
            <div
                class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"
            >
                <div>
                    <h2 class="text-lg font-semibold">Test app session</h2>
                    <p class="text-sm text-muted-foreground">
                        Switch authentication state for the seeded admin user.
                    </p>
                    <p class="mt-2 text-sm">
                        <span class="text-muted-foreground">Current user:</span>
                        <span class="font-medium text-foreground">{{
                            authLabel
                        }}</span>
                    </p>
                </div>
                <div class="flex flex-wrap gap-3">
                    <a
                        class="inline-flex items-center justify-center rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground transition hover:bg-primary/90"
                        href="/auth/login"
                    >
                        Log in as admin
                    </a>
                    <a
                        class="inline-flex items-center justify-center rounded-md border border-input px-4 py-2 text-sm font-medium text-foreground transition hover:bg-muted"
                        href="/auth/logout"
                    >
                        Log out
                    </a>
                </div>
            </div>
        </section>

        <section class="rounded-xl border bg-card p-6">
            <div
                class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"
            >
                <div>
                    <h2 class="text-lg font-semibold">Test translations</h2>
                    <p class="mt-2 text-sm">
                        <span class="text-muted-foreground">Regular: </span>
                        <span class="font-medium text-foreground">{{
                            $t('frontend.Regular translation')
                        }}</span>
                    </p>
                    <p class="mt-2 text-sm">
                        <span class="text-muted-foreground">Short 1: </span>
                        <span class="font-medium text-foreground">{{
                            $t('frontend.Works.')
                        }}</span>
                    </p>
                    <p class="mt-2 text-sm">
                        <span class="text-muted-foreground">Short 2: </span>
                        <span class="font-medium text-foreground">{{
                            $t('frontend.Also works.')
                        }}</span>
                    </p>
                    <p class="mt-2 text-sm">
                        <span class="text-muted-foreground">Short 3: </span>
                        <span class="font-medium text-foreground">{{
                            $t('frontend.grouped.Works.')
                        }}</span>
                    </p>
                    <p class="mt-2 text-sm">
                        <span class="text-muted-foreground">Phrase 1: </span>
                        <span class="font-medium text-foreground">{{
                            $t(
                                'frontend.Lorem ipsum dolor sit amet, consectetur adipiscing elit. Sed do eiusmod tempor incididunt ut labore et dolore magna aliqua. Ut enim ad minim veniam, quis nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo consequat.',
                            )
                        }}</span>
                    </p>
                    <p class="mt-2 text-sm">
                        <span class="text-muted-foreground">Phrase 2: </span>
                        <span class="font-medium text-foreground">{{
                            $t(
                                'frontend.grouped.Lorem ipsum dolor sit amet, consectetur adipiscing elit. Sed do eiusmod tempor incididunt ut labore et dolore magna aliqua. Ut enim ad minim veniam, quis nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo consequat.',
                            )
                        }}</span>
                    </p>
                    <p class="mt-2 text-sm">
                        <span class="text-muted-foreground"
                            >Multiline Phrase:
                        </span>
                        <span class="font-medium text-foreground">{{
                            $t(`frontend.Lorem ipsum dolor sit amet, consectetur adipiscing elit.
                                Sed do eiusmod tempor incididunt ut labore et dolore magna aliqua.
                                Ut enim ad minim veniam, quis nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo consequat.`)
                        }}</span>
                    </p>
                    <p class="mt-2 text-sm">
                        <span class="text-muted-foreground">JSON 1: </span>
                        <span class="font-medium text-foreground">{{
                            $t('This ends up in JSON')
                        }}</span>
                    </p>
                    <p class="mt-2 text-sm">
                        <span class="text-muted-foreground">JSON 2: </span>
                        <span class="font-medium text-foreground">{{
                            $t('This ends up in JSON too.')
                        }}</span>
                    </p>
                    <p class="mt-2 text-sm">
                        <span class="text-muted-foreground"
                            >JSON Multiline Phrase:
                        </span>
                        <span class="font-medium text-foreground">{{
                            $t(`Lorem ipsum dolor sit amet, consectetur adipiscing elit.
                                Sed do eiusmod tempor incididunt ut labore et dolore magna aliqua.
                                Ut enim ad minim veniam, quis nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo consequat.`)
                        }}</span>
                    </p>
                    <div class="mt-2 text-sm">
                        <span class="text-muted-foreground"
                            >Parametrized:
                        </span>
                        <div>
                            <span class="text-muted-foreground"
                                >Parametrized 1:
                            </span>
                            <span class="font-medium text-foreground">{{
                                $t('frontend.Today is :date', {
                                    date: new Date().toLocaleDateString(),
                                })
                            }}</span>
                        </div>
                        <div>
                            <span class="text-muted-foreground"
                                >Parametrized 2:
                            </span>
                            <span class="font-medium text-foreground">{{
                                $t('frontend.Today is :date, :time.', {
                                    date: new Date().toLocaleDateString(),
                                    time: new Date().toLocaleTimeString(),
                                })
                            }}</span>
                        </div>
                    </div>
                    <div class="mt-2 text-sm">
                        <span class="text-muted-foreground">Dynamic: </span>
                        <div v-for="(label, key) in dynamicLabels" :key="key">
                            <span class="text-muted-foreground">
                                {{ label }}:
                            </span>
                            <span class="font-medium text-foreground">
                                {{ $t('frontend.dynamicLabels.values.' + key) }}
                            </span>
                        </div>
                    </div>
                    <div class="mt-2 text-sm">
                        <span class="text-muted-foreground">Dynamic 2: </span>
                        <div v-for="(label, key) in dynamicLabels2" :key="key">
                            <span class="text-muted-foreground">
                                {{ label }}:
                            </span>
                            <span class="font-medium text-foreground">
                                {{
                                    $t(`frontend.dynamicLabels2.values.${key}`)
                                }}
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section class="rounded-xl border bg-card p-6">
            <div class="flex flex-col gap-6">
                <div
                    class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between"
                >
                    <div>
                        <h2 class="text-lg font-semibold">
                            {{ $t('form_section.title') }}
                        </h2>
                        <p class="text-sm text-muted-foreground">
                            {{ $t('form_section.subtitle') }}
                        </p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <button
                            class="inline-flex items-center justify-center rounded-md border border-input px-4 py-2 text-sm font-medium text-foreground transition hover:bg-muted"
                            type="button"
                        >
                            {{ $t('form_section.actions.back') }}
                        </button>
                        <button
                            class="inline-flex items-center justify-center rounded-md border border-input px-4 py-2 text-sm font-medium text-foreground transition hover:bg-muted"
                            type="button"
                        >
                            {{ $t('form_section.actions.cancel') }}
                        </button>
                        <button
                            class="inline-flex items-center justify-center rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground transition hover:bg-primary/90"
                            type="button"
                        >
                            {{ $t('form_section.actions.save') }}
                        </button>
                    </div>
                </div>

                <form class="grid gap-4 sm:grid-cols-2">
                    <div class="flex flex-col gap-2">
                        <label class="text-sm font-medium" for="form-full-name">
                            {{ $t('form_section.fields.full_name.label') }}
                        </label>
                        <input
                            id="form-full-name"
                            class="h-10 rounded-md border border-input bg-background px-3 text-sm"
                            type="text"
                            :placeholder="
                                $t('form_section.fields.full_name.placeholder')
                            "
                        />
                        <p class="text-xs text-muted-foreground">
                            {{ $t('form_section.fields.full_name.helper') }}
                        </p>
                    </div>

                    <div class="flex flex-col gap-2">
                        <label
                            class="text-sm font-medium"
                            for="form-display-name"
                        >
                            {{ $t('form_section.fields.display_name.label') }}
                        </label>
                        <input
                            id="form-display-name"
                            class="h-10 rounded-md border border-input bg-background px-3 text-sm"
                            type="text"
                            :placeholder="
                                $t(
                                    'form_section.fields.display_name.placeholder',
                                )
                            "
                        />
                    </div>

                    <div class="flex flex-col gap-2">
                        <label class="text-sm font-medium" for="form-email">
                            {{ $t('form_section.fields.email.label') }}
                        </label>
                        <input
                            id="form-email"
                            class="h-10 rounded-md border border-input bg-background px-3 text-sm"
                            type="email"
                            inputmode="email"
                            :placeholder="
                                $t('form_section.fields.email.placeholder')
                            "
                        />
                        <p class="text-xs text-muted-foreground">
                            {{ $t('form_section.fields.email.helper') }}
                        </p>
                    </div>

                    <div class="flex flex-col gap-2">
                        <label class="text-sm font-medium" for="form-amount">
                            {{ $t('form_section.fields.amount.label') }}
                        </label>
                        <input
                            id="form-amount"
                            class="h-10 rounded-md border border-input bg-background px-3 text-sm"
                            type="text"
                            inputmode="decimal"
                            :placeholder="
                                $t('form_section.fields.amount.placeholder')
                            "
                        />
                        <p class="text-xs text-muted-foreground">
                            {{ $t('form_section.fields.amount.helper') }}
                        </p>
                    </div>

                    <div class="flex flex-col gap-2">
                        <label class="text-sm font-medium" for="form-status">
                            {{ $t('form_section.fields.status.label') }}
                        </label>
                        <select
                            id="form-status"
                            class="h-10 rounded-md border border-input bg-background px-3 text-sm"
                        >
                            <option value="draft">
                                {{
                                    $t(
                                        'form_section.fields.status.options.draft',
                                    )
                                }}
                            </option>
                            <option value="on-hold">
                                {{
                                    $t(
                                        'form_section.fields.status.options.on_hold',
                                    )
                                }}
                            </option>
                            <option value="due-now">
                                {{
                                    $t(
                                        'form_section.fields.status.options.due_now',
                                    )
                                }}
                            </option>
                            <option value="paid">
                                {{
                                    $t(
                                        'form_section.fields.status.options.paid',
                                    )
                                }}
                            </option>
                        </select>
                    </div>

                    <div class="flex flex-col gap-2">
                        <label class="text-sm font-medium" for="form-reference">
                            {{ $t('form_section.fields.reference.label') }}
                        </label>
                        <input
                            id="form-reference"
                            class="h-10 rounded-md border border-input bg-background px-3 text-sm"
                            type="text"
                            :placeholder="
                                $t('form_section.fields.reference.placeholder')
                            "
                        />
                        <p class="text-xs text-muted-foreground">
                            {{
                                $t(
                                    'form_section.Use :and to reach :to in :in',
                                    {
                                        and: '#',
                                        to: '42',
                                        in: '2025',
                                    },
                                )
                            }}
                        </p>
                    </div>

                    <div class="flex flex-col gap-2 sm:col-span-2">
                        <label class="text-sm font-medium" for="form-notes">
                            {{ $t('form_section.fields.notes.label') }}
                        </label>
                        <textarea
                            id="form-notes"
                            class="min-h-[96px] rounded-md border border-input bg-background px-3 py-2 text-sm"
                            :placeholder="
                                $t('form_section.fields.notes.placeholder')
                            "
                        ></textarea>
                    </div>

                    <div class="flex items-center gap-2 text-sm">
                        <input
                            id="form-shipping"
                            class="h-4 w-4 rounded border border-input"
                            type="checkbox"
                        />
                        <label class="text-sm" for="form-shipping">
                            {{ $t('form_section.fields.shipping_same.label') }}
                        </label>
                    </div>

                    <div class="flex items-center gap-2 text-sm">
                        <input
                            id="form-consent"
                            class="h-4 w-4 rounded border border-input"
                            type="checkbox"
                        />
                        <label class="text-sm" for="form-consent">
                            {{ $t('form_section.fields.consent.label') }}
                        </label>
                    </div>
                </form>

                <div
                    class="flex flex-col gap-2 rounded-lg border border-dashed border-input bg-muted/40 p-4 text-sm"
                >
                    <p class="font-medium">
                        {{ $t('form_section.helper.title') }}
                    </p>
                    <p class="text-muted-foreground">
                        {{ $t('form_section.helper.description') }}
                    </p>
                    <p class="text-muted-foreground">
                        {{
                            $t(
                                'form_section.Move from :from to :to on :on for :for',
                                {
                                    from: '9:00',
                                    to: '17:00',
                                    on: 'Monday',
                                    for: '3 weeks',
                                },
                            )
                        }}
                    </p>
                </div>

                <div class="flex flex-wrap gap-2">
                    <button
                        class="inline-flex items-center justify-center rounded-md border border-input px-4 py-2 text-sm font-medium text-foreground transition hover:bg-muted"
                        type="button"
                    >
                        {{ $t('form_section.actions.save_draft') }}
                    </button>
                    <button
                        class="inline-flex items-center justify-center rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground transition hover:bg-primary/90"
                        type="button"
                    >
                        {{ $t('form_section.actions.save_and_close') }}
                    </button>
                </div>
            </div>
        </section>
    </section>
</template>
