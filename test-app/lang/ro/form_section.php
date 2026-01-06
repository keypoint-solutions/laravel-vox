<?php

// @formatter:off
// phpcs:disable
// phpcs:ignoreFile
// phpstan-ignore-file
// psalm-disable-file

return [
    // <p class="text-muted-foreground"> {{ $t(KEY, { from: '9:00', to: '17:00',
    // resources/js/pages/Welcome.vue:448
    'Move from :from to :to on :on for :for' => 'Mută de la :from la :to pe :on pentru :for',
    // <p class="text-xs text-muted-foreground"> {{ $t(KEY, { and: '#', to: '42',
    // resources/js/pages/Welcome.vue:389
    'Use :and to reach :to in :in' => 'Folosește :and pentru a ajunge la :to în :in',
    // border border-input px-4 py-2 text-sm font-medium text-foreground transition hover:bg-muted" type="button" > {{ $t(KEY) }} </button> <button class="inline-flex items-center justify-center rounded-md border border-input px-4 py-2 text-sm
    // resources/js/pages/Welcome.vue:245
    'actions.back' => 'Înapoi',
    // border border-input px-4 py-2 text-sm font-medium text-foreground transition hover:bg-muted" type="button" > {{ $t(KEY) }} </button> <button class="inline-flex items-center justify-center rounded-md bg-primary px-4 py-2 text-sm
    // resources/js/pages/Welcome.vue:251
    'actions.cancel' => 'Anulează',
    // px-4 py-2 text-sm font-medium text-primary-foreground transition hover:bg-primary/90" type="button" > {{ $t(KEY) }} </button> </div> </div>
    // resources/js/pages/Welcome.vue:257
    'actions.save' => 'Salvează',
    // px-4 py-2 text-sm font-medium text-primary-foreground transition hover:bg-primary/90" type="button" > {{ $t(KEY) }} </button> </div> </div>
    // resources/js/pages/Welcome.vue:471
    'actions.save_and_close' => 'Salvează și închide',
    // border border-input px-4 py-2 text-sm font-medium text-foreground transition hover:bg-muted" type="button" > {{ $t(KEY) }} </button> <button class="inline-flex items-center justify-center rounded-md bg-primary px-4 py-2 text-sm
    // resources/js/pages/Welcome.vue:465
    'actions.save_draft' => 'Salvează schița',
    // " /> <p class="text-xs text-muted-foreground"> {{ $t(KEY) }} </p> </div>
    // resources/js/pages/Welcome.vue:331
    'fields.amount.helper' => 'Taxa este un substantiv aici, nu o acțiune.',
    // <div class="flex flex-col gap-2"> <label class="text-sm font-medium" for="form-amount"> {{ $t(KEY) }} </label> <input id="form-amount"
    // resources/js/pages/Welcome.vue:319
    'fields.amount.label' => 'Suma de încărcare',
    // type="text" inputmode="decimal" :placeholder=" $t(KEY) " /> <p class="text-xs text-muted-foreground">
    // resources/js/pages/Welcome.vue:327
    'fields.amount.placeholder' => '125,00',
    // type="checkbox" /> <label class="text-sm" for="form-consent"> {{ $t(KEY) }} </label> </div> </form>
    // resources/js/pages/Welcome.vue:431
    'fields.consent.label' => 'Sunt de acord cu termenii de procesare.',
    // class="text-sm font-medium" for="form-display-name" > {{ $t(KEY) }} </label> <input id="form-display-name"
    // resources/js/pages/Welcome.vue:285
    'fields.display_name.label' => 'Nume afișat',
    // type="text" :placeholder=" $t(KEY, ) " />
    // resources/js/pages/Welcome.vue:293
    'fields.display_name.placeholder' => 'Nume pe profilul public',
    // " /> <p class="text-xs text-muted-foreground"> {{ $t(KEY) }} </p> </div>
    // resources/js/pages/Welcome.vue:313
    'fields.email.helper' => 'Nu împărtășim niciodată această adresă.',
    // <div class="flex flex-col gap-2"> <label class="text-sm font-medium" for="form-email"> {{ $t(KEY) }} </label> <input id="form-email"
    // resources/js/pages/Welcome.vue:301
    'fields.email.label' => 'Email pentru chitanțe',
    // type="email" inputmode="email" :placeholder=" $t(KEY) " /> <p class="text-xs text-muted-foreground">
    // resources/js/pages/Welcome.vue:309
    'fields.email.placeholder' => 'name@company.com',
    // " /> <p class="text-xs text-muted-foreground"> {{ $t(KEY) }} </p> </div>
    // resources/js/pages/Welcome.vue:276
    'fields.full_name.helper' => 'Folosește numele care trebuie să apară pe factură.',
    // sm:grid-cols-2"> <div class="flex flex-col gap-2"> <label class="text-sm font-medium" for="form-full-name"> {{ $t(KEY) }} </label> <input id="form-full-name"
    // resources/js/pages/Welcome.vue:265
    'fields.full_name.label' => 'Nume complet',
    // class="h-10 rounded-md border border-input bg-background px-3 text-sm" type="text" :placeholder=" $t(KEY) " /> <p class="text-xs text-muted-foreground">
    // resources/js/pages/Welcome.vue:272
    'fields.full_name.placeholder' => 'de ex. Alex Rivera',
    // <div class="flex flex-col gap-2 sm:col-span-2"> <label class="text-sm font-medium" for="form-notes"> {{ $t(KEY) }} </label> <textarea id="form-notes"
    // resources/js/pages/Welcome.vue:402
    'fields.notes.label' => 'Note interne',
    // id="form-notes" class="min-h-[96px] rounded-md border border-input bg-background px-3 py-2 text-sm" :placeholder=" $t(KEY) " ></textarea> </div>
    // resources/js/pages/Welcome.vue:408
    'fields.notes.placeholder' => 'Aceste note sunt doar pentru echipa ta.',
    // <div class="flex flex-col gap-2"> <label class="text-sm font-medium" for="form-reference"> {{ $t(KEY) }} </label> <input id="form-reference"
    // resources/js/pages/Welcome.vue:376
    'fields.reference.label' => 'Cod de referință',
    // class="h-10 rounded-md border border-input bg-background px-3 text-sm" type="text" :placeholder=" $t(KEY) " /> <p class="text-xs text-muted-foreground">
    // resources/js/pages/Welcome.vue:383
    'fields.reference.placeholder' => 'INV-2025-0042',
    // type="checkbox" /> <label class="text-sm" for="form-shipping"> {{ $t(KEY) }} </label> </div>
    // resources/js/pages/Welcome.vue:420
    'fields.shipping_same.label' => 'Adresa de livrare este aceeași cu cea de facturare.',
    // <div class="flex flex-col gap-2"> <label class="text-sm font-medium" for="form-status"> {{ $t(KEY) }} </label> <select id="form-status"
    // resources/js/pages/Welcome.vue:337
    'fields.status.label' => 'Stare',
    // <option value="draft"> {{ $t(KEY, ) }} </option>
    // resources/js/pages/Welcome.vue:346
    'fields.status.options.draft' => 'Ciornă',
    // <option value="due-now"> {{ $t(KEY, ) }} </option>
    // resources/js/pages/Welcome.vue:360
    'fields.status.options.due_now' => 'Scadent acum',
    // <option value="on-hold"> {{ $t(KEY, ) }} </option>
    // resources/js/pages/Welcome.vue:353
    'fields.status.options.on_hold' => 'În așteptare',
    // <option value="paid"> {{ $t(KEY, ) }} </option>
    // resources/js/pages/Welcome.vue:367
    'fields.status.options.paid' => 'Plătit',
    // {{ $t('form_section.helper.title') }} </p> <p class="text-muted-foreground"> {{ $t(KEY) }} </p> <p class="text-muted-foreground"> {{
    // resources/js/pages/Welcome.vue:443
    'helper.description' => '„Salvează” stochează modificările, în timp ce „Salvează schița” le păstrează private.',
    // flex-col gap-2 rounded-lg border border-dashed border-input bg-muted/40 p-4 text-sm" > <p class="font-medium"> {{ $t(KEY) }} </p> <p class="text-muted-foreground"> {{ $t('form_section.helper.description') }}
    // resources/js/pages/Welcome.vue:440
    'helper.title' => 'Verificare context',
    // {{ $t('form_section.title') }} </h2> <p class="text-sm text-muted-foreground"> {{ $t(KEY) }} </p> </div> <div class="flex flex-wrap gap-2">
    // resources/js/pages/Welcome.vue:237
    'subtitle' => 'Etichetele scurte de mai jos se bazează pe context, nu pe sensul literal.',
    // > <div> <h2 class="text-lg font-semibold"> {{ $t(KEY) }} </h2> <p class="text-sm text-muted-foreground"> {{ $t('form_section.subtitle') }}
    // resources/js/pages/Welcome.vue:234
    'title' => 'Formular de verificare a traducerii',
];
