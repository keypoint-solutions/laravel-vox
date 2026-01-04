<?php

// @formatter:off
// phpcs:disable
// phpcs:ignoreFile
// phpstan-ignore-file
// psalm-disable-file

return [
    // <p class="text-muted-foreground"> {{ $t(KEY, { from: '9:00', to: '17:00',
    // resources/js/pages/Welcome.vue:388
    'Move from :from to :to on :on for :for' => 'Move from :from to :to on :on for :for',
    // <p class="text-xs text-muted-foreground"> {{ $t(KEY, { and: '#', to: '42',
    // resources/js/pages/Welcome.vue:333
    'Use :and to reach :to in :in' => 'Use :and to reach :to in :in',
    // border border-input px-4 py-2 text-sm font-medium text-foreground transition hover:bg-muted" type="button" > {{ $t(KEY) }} </button> <button class="inline-flex items-center justify-center rounded-md border border-input px-4 py-2 text-sm
    // resources/js/pages/Welcome.vue:220
    'actions.back' => 'Back',
    // border border-input px-4 py-2 text-sm font-medium text-foreground transition hover:bg-muted" type="button" > {{ $t(KEY) }} </button> <button class="inline-flex items-center justify-center rounded-md bg-primary px-4 py-2 text-sm
    // resources/js/pages/Welcome.vue:226
    'actions.cancel' => 'Cancel',
    // px-4 py-2 text-sm font-medium text-primary-foreground transition hover:bg-primary/90" type="button" > {{ $t(KEY) }} </button> </div> </div>
    // resources/js/pages/Welcome.vue:232
    'actions.save' => 'Save',
    // px-4 py-2 text-sm font-medium text-primary-foreground transition hover:bg-primary/90" type="button" > {{ $t(KEY) }} </button> </div> </div>
    // resources/js/pages/Welcome.vue:411
    'actions.save_and_close' => 'Save & close',
    // border border-input px-4 py-2 text-sm font-medium text-foreground transition hover:bg-muted" type="button" > {{ $t(KEY) }} </button> <button class="inline-flex items-center justify-center rounded-md bg-primary px-4 py-2 text-sm
    // resources/js/pages/Welcome.vue:405
    'actions.save_draft' => 'Save draft',
    // :placeholder="$t('form_section.fields.amount.placeholder')" > <p class="text-xs text-muted-foreground"> {{ $t(KEY) }} </p> </div>
    // resources/js/pages/Welcome.vue:293
    'fields.amount.helper' => 'Charge is a noun here, not the action.',
    // <div class="flex flex-col gap-2"> <label class="text-sm font-medium" for="form-amount"> {{ $t(KEY) }} </label> <input id="form-amount"
    // resources/js/pages/Welcome.vue:283
    'fields.amount.label' => 'Charge amount',
    // rounded-md border border-input bg-background px-3 text-sm" type="text" inputmode="decimal" :placeholder="$t(KEY)" > <p class="text-xs text-muted-foreground"> {{ $t('form_section.fields.amount.helper') }}
    // resources/js/pages/Welcome.vue:290
    'fields.amount.placeholder' => '125.00',
    // type="checkbox" > <label class="text-sm" for="form-consent"> {{ $t(KEY) }} </label> </div> </form>
    // resources/js/pages/Welcome.vue:373
    'fields.consent.label' => 'I agree to the processing terms.',
    // <div class="flex flex-col gap-2"> <label class="text-sm font-medium" for="form-display-name"> {{ $t(KEY) }} </label> <input id="form-display-name"
    // resources/js/pages/Welcome.vue:255
    'fields.display_name.label' => 'Display name',
    // class="h-10 rounded-md border border-input bg-background px-3 text-sm" type="text" :placeholder="$t(KEY)" > </div>
    // resources/js/pages/Welcome.vue:261
    'fields.display_name.placeholder' => 'Name on public profile',
    // :placeholder="$t('form_section.fields.email.placeholder')" > <p class="text-xs text-muted-foreground"> {{ $t(KEY) }} </p> </div>
    // resources/js/pages/Welcome.vue:277
    'fields.email.helper' => 'We never share this address.',
    // <div class="flex flex-col gap-2"> <label class="text-sm font-medium" for="form-email"> {{ $t(KEY) }} </label> <input id="form-email"
    // resources/js/pages/Welcome.vue:267
    'fields.email.label' => 'Email for receipts',
    // class="h-10 rounded-md border border-input bg-background px-3 text-sm" type="email" inputmode="email" :placeholder="$t(KEY)" > <p class="text-xs text-muted-foreground"> {{ $t('form_section.fields.email.helper') }}
    // resources/js/pages/Welcome.vue:274
    'fields.email.placeholder' => 'name@company.com',
    // :placeholder="$t('form_section.fields.full_name.placeholder')" > <p class="text-xs text-muted-foreground"> {{ $t(KEY) }} </p> </div>
    // resources/js/pages/Welcome.vue:249
    'fields.full_name.helper' => 'Use the name that should appear on the invoice.',
    // sm:grid-cols-2"> <div class="flex flex-col gap-2"> <label class="text-sm font-medium" for="form-full-name"> {{ $t(KEY) }} </label> <input id="form-full-name"
    // resources/js/pages/Welcome.vue:240
    'fields.full_name.label' => 'Full name',
    // class="h-10 rounded-md border border-input bg-background px-3 text-sm" type="text" :placeholder="$t(KEY)" > <p class="text-xs text-muted-foreground"> {{ $t('form_section.fields.full_name.helper') }}
    // resources/js/pages/Welcome.vue:246
    'fields.full_name.placeholder' => 'e.g. Alex Rivera',
    // <div class="flex flex-col gap-2 sm:col-span-2"> <label class="text-sm font-medium" for="form-notes"> {{ $t(KEY) }} </label> <textarea id="form-notes"
    // resources/js/pages/Welcome.vue:346
    'fields.notes.label' => 'Internal notes',
    // id="form-notes" class="min-h-[96px] rounded-md border border-input bg-background px-3 py-2 text-sm" :placeholder="$t(KEY)" ></textarea> </div>
    // resources/js/pages/Welcome.vue:351
    'fields.notes.placeholder' => 'These notes are for your team only.',
    // <div class="flex flex-col gap-2"> <label class="text-sm font-medium" for="form-reference"> {{ $t(KEY) }} </label> <input id="form-reference"
    // resources/js/pages/Welcome.vue:322
    'fields.reference.label' => 'Reference code',
    // class="h-10 rounded-md border border-input bg-background px-3 text-sm" type="text" :placeholder="$t(KEY)" > <p class="text-xs text-muted-foreground"> {{
    // resources/js/pages/Welcome.vue:328
    'fields.reference.placeholder' => 'INV-2025-0042',
    // type="checkbox" > <label class="text-sm" for="form-shipping"> {{ $t(KEY) }} </label> </div>
    // resources/js/pages/Welcome.vue:362
    'fields.shipping_same.label' => 'Shipping address is the same as billing.',
    // <div class="flex flex-col gap-2"> <label class="text-sm font-medium" for="form-status"> {{ $t(KEY) }} </label> <select id="form-status"
    // resources/js/pages/Welcome.vue:299
    'fields.status.label' => 'Status',
    // class="h-10 rounded-md border border-input bg-background px-3 text-sm" > <option value="draft"> {{ $t(KEY) }} </option> <option value="on-hold"> {{ $t('form_section.fields.status.options.on_hold') }}
    // resources/js/pages/Welcome.vue:306
    'fields.status.options.draft' => 'Draft',
    // {{ $t('form_section.fields.status.options.on_hold') }} </option> <option value="due-now"> {{ $t(KEY) }} </option> <option value="paid"> {{ $t('form_section.fields.status.options.paid') }}
    // resources/js/pages/Welcome.vue:312
    'fields.status.options.due_now' => 'Due now',
    // {{ $t('form_section.fields.status.options.draft') }} </option> <option value="on-hold"> {{ $t(KEY) }} </option> <option value="due-now"> {{ $t('form_section.fields.status.options.due_now') }}
    // resources/js/pages/Welcome.vue:309
    'fields.status.options.on_hold' => 'On hold',
    // {{ $t('form_section.fields.status.options.due_now') }} </option> <option value="paid"> {{ $t(KEY) }} </option> </select> </div>
    // resources/js/pages/Welcome.vue:315
    'fields.status.options.paid' => 'Paid',
    // {{ $t('form_section.helper.title') }} </p> <p class="text-muted-foreground"> {{ $t(KEY) }} </p> <p class="text-muted-foreground"> {{
    // resources/js/pages/Welcome.vue:383
    'helper.description' => '"Save" stores changes, while "Save draft" keeps them private.',
    // flex-col gap-2 rounded-lg border border-dashed border-input bg-muted/40 p-4 text-sm"> <p class="font-medium"> {{ $t(KEY) }} </p> <p class="text-muted-foreground"> {{ $t('form_section.helper.description') }}
    // resources/js/pages/Welcome.vue:380
    'helper.title' => 'Context check',
    // <h2 class="text-lg font-semibold">{{ $t('form_section.title') }}</h2> <p class="text-sm text-muted-foreground"> {{ $t(KEY) }} </p> </div> <div class="flex flex-wrap gap-2">
    // resources/js/pages/Welcome.vue:212
    'subtitle' => 'Short labels below rely on context, not literal meaning.',
    // flex-col gap-3 sm:flex-row sm:items-start sm:justify-between"> <div> <h2 class="text-lg font-semibold">{{ $t(KEY) }}</h2> <p class="text-sm text-muted-foreground"> {{ $t('form_section.subtitle') }} </p>
    // resources/js/pages/Welcome.vue:210
    'title' => 'Translation check-in form',
];
