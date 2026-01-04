<?php

// @formatter:off
// phpcs:disable
// phpcs:ignoreFile
// phpstan-ignore-file
// psalm-disable-file

return [
    // <p class="text-muted-foreground"> {{ $t(KEY, { from: '9:00', to: '17:00',
    // resources/js/pages/Welcome.vue:388
    'Move from :from to :to on :on for :for' => 'Déplacez de :from à :to le :on pour :for',
    // <p class="text-xs text-muted-foreground"> {{ $t(KEY, { and: '#', to: '42',
    // resources/js/pages/Welcome.vue:333
    'Use :and to reach :to in :in' => 'Utilisez :and pour atteindre :to dans :in',
    // border border-input px-4 py-2 text-sm font-medium text-foreground transition hover:bg-muted" type="button" > {{ $t(KEY) }} </button> <button class="inline-flex items-center justify-center rounded-md border border-input px-4 py-2 text-sm
    // resources/js/pages/Welcome.vue:220
    'actions.back' => 'Retour',
    // border border-input px-4 py-2 text-sm font-medium text-foreground transition hover:bg-muted" type="button" > {{ $t(KEY) }} </button> <button class="inline-flex items-center justify-center rounded-md bg-primary px-4 py-2 text-sm
    // resources/js/pages/Welcome.vue:226
    'actions.cancel' => 'Annuler',
    // px-4 py-2 text-sm font-medium text-primary-foreground transition hover:bg-primary/90" type="button" > {{ $t(KEY) }} </button> </div> </div>
    // resources/js/pages/Welcome.vue:232
    'actions.save' => 'Sauvegarder',
    // px-4 py-2 text-sm font-medium text-primary-foreground transition hover:bg-primary/90" type="button" > {{ $t(KEY) }} </button> </div> </div>
    // resources/js/pages/Welcome.vue:411
    'actions.save_and_close' => 'Enregistrer et fermer',
    // border border-input px-4 py-2 text-sm font-medium text-foreground transition hover:bg-muted" type="button" > {{ $t(KEY) }} </button> <button class="inline-flex items-center justify-center rounded-md bg-primary px-4 py-2 text-sm
    // resources/js/pages/Welcome.vue:405
    'actions.save_draft' => 'Enregistrer le brouillon',
    // :placeholder="$t('form_section.fields.amount.placeholder')" > <p class="text-xs text-muted-foreground"> {{ $t(KEY) }} </p> </div>
    // resources/js/pages/Welcome.vue:293
    'fields.amount.helper' => 'Le montant est un nom ici, pas l\'action.',
    // <div class="flex flex-col gap-2"> <label class="text-sm font-medium" for="form-amount"> {{ $t(KEY) }} </label> <input id="form-amount"
    // resources/js/pages/Welcome.vue:283
    'fields.amount.label' => 'Montant de la charge',
    // rounded-md border border-input bg-background px-3 text-sm" type="text" inputmode="decimal" :placeholder="$t(KEY)" > <p class="text-xs text-muted-foreground"> {{ $t('form_section.fields.amount.helper') }}
    // resources/js/pages/Welcome.vue:290
    'fields.amount.placeholder' => '125,00',
    // type="checkbox" > <label class="text-sm" for="form-consent"> {{ $t(KEY) }} </label> </div> </form>
    // resources/js/pages/Welcome.vue:373
    'fields.consent.label' => 'Je consens aux conditions de traitement.',
    // <div class="flex flex-col gap-2"> <label class="text-sm font-medium" for="form-display-name"> {{ $t(KEY) }} </label> <input id="form-display-name"
    // resources/js/pages/Welcome.vue:255
    'fields.display_name.label' => 'Nom d\'affichage',
    // class="h-10 rounded-md border border-input bg-background px-3 text-sm" type="text" :placeholder="$t(KEY)" > </div>
    // resources/js/pages/Welcome.vue:261
    'fields.display_name.placeholder' => 'Nom sur le profil public',
    // :placeholder="$t('form_section.fields.email.placeholder')" > <p class="text-xs text-muted-foreground"> {{ $t(KEY) }} </p> </div>
    // resources/js/pages/Welcome.vue:277
    'fields.email.helper' => 'Nous ne partageons jamais cette adresse.',
    // <div class="flex flex-col gap-2"> <label class="text-sm font-medium" for="form-email"> {{ $t(KEY) }} </label> <input id="form-email"
    // resources/js/pages/Welcome.vue:267
    'fields.email.label' => 'Email pour les reçus',
    // class="h-10 rounded-md border border-input bg-background px-3 text-sm" type="email" inputmode="email" :placeholder="$t(KEY)" > <p class="text-xs text-muted-foreground"> {{ $t('form_section.fields.email.helper') }}
    // resources/js/pages/Welcome.vue:274
    'fields.email.placeholder' => 'nom@entreprise.com',
    // :placeholder="$t('form_section.fields.full_name.placeholder')" > <p class="text-xs text-muted-foreground"> {{ $t(KEY) }} </p> </div>
    // resources/js/pages/Welcome.vue:249
    'fields.full_name.helper' => 'Utilisez le nom qui doit apparaître sur la facture.',
    // sm:grid-cols-2"> <div class="flex flex-col gap-2"> <label class="text-sm font-medium" for="form-full-name"> {{ $t(KEY) }} </label> <input id="form-full-name"
    // resources/js/pages/Welcome.vue:240
    'fields.full_name.label' => 'Nom complet',
    // class="h-10 rounded-md border border-input bg-background px-3 text-sm" type="text" :placeholder="$t(KEY)" > <p class="text-xs text-muted-foreground"> {{ $t('form_section.fields.full_name.helper') }}
    // resources/js/pages/Welcome.vue:246
    'fields.full_name.placeholder' => 'par exemple, Alex Rivera',
    // <div class="flex flex-col gap-2 sm:col-span-2"> <label class="text-sm font-medium" for="form-notes"> {{ $t(KEY) }} </label> <textarea id="form-notes"
    // resources/js/pages/Welcome.vue:346
    'fields.notes.label' => 'Notes internes',
    // id="form-notes" class="min-h-[96px] rounded-md border border-input bg-background px-3 py-2 text-sm" :placeholder="$t(KEY)" ></textarea> </div>
    // resources/js/pages/Welcome.vue:351
    'fields.notes.placeholder' => 'Ces notes sont uniquement pour votre équipe.',
    // <div class="flex flex-col gap-2"> <label class="text-sm font-medium" for="form-reference"> {{ $t(KEY) }} </label> <input id="form-reference"
    // resources/js/pages/Welcome.vue:322
    'fields.reference.label' => 'Code de référence',
    // class="h-10 rounded-md border border-input bg-background px-3 text-sm" type="text" :placeholder="$t(KEY)" > <p class="text-xs text-muted-foreground"> {{
    // resources/js/pages/Welcome.vue:328
    'fields.reference.placeholder' => 'INV-2025-0042',
    // type="checkbox" > <label class="text-sm" for="form-shipping"> {{ $t(KEY) }} </label> </div>
    // resources/js/pages/Welcome.vue:362
    'fields.shipping_same.label' => 'L\'adresse de livraison est la même que l\'adresse de facturation.',
    // <div class="flex flex-col gap-2"> <label class="text-sm font-medium" for="form-status"> {{ $t(KEY) }} </label> <select id="form-status"
    // resources/js/pages/Welcome.vue:299
    'fields.status.label' => 'Statut',
    // class="h-10 rounded-md border border-input bg-background px-3 text-sm" > <option value="draft"> {{ $t(KEY) }} </option> <option value="on-hold"> {{ $t('form_section.fields.status.options.on_hold') }}
    // resources/js/pages/Welcome.vue:306
    'fields.status.options.draft' => 'Brouillon',
    // {{ $t('form_section.fields.status.options.on_hold') }} </option> <option value="due-now"> {{ $t(KEY) }} </option> <option value="paid"> {{ $t('form_section.fields.status.options.paid') }}
    // resources/js/pages/Welcome.vue:312
    'fields.status.options.due_now' => 'Dû maintenant',
    // {{ $t('form_section.fields.status.options.draft') }} </option> <option value="on-hold"> {{ $t(KEY) }} </option> <option value="due-now"> {{ $t('form_section.fields.status.options.due_now') }}
    // resources/js/pages/Welcome.vue:309
    'fields.status.options.on_hold' => 'En attente',
    // {{ $t('form_section.fields.status.options.due_now') }} </option> <option value="paid"> {{ $t(KEY) }} </option> </select> </div>
    // resources/js/pages/Welcome.vue:315
    'fields.status.options.paid' => 'Payé',
    // {{ $t('form_section.helper.title') }} </p> <p class="text-muted-foreground"> {{ $t(KEY) }} </p> <p class="text-muted-foreground"> {{
    // resources/js/pages/Welcome.vue:383
    'helper.description' => '"Enregistrer" sauvegarde les modifications, tandis que "Enregistrer le brouillon" les garde privées.',
    // flex-col gap-2 rounded-lg border border-dashed border-input bg-muted/40 p-4 text-sm"> <p class="font-medium"> {{ $t(KEY) }} </p> <p class="text-muted-foreground"> {{ $t('form_section.helper.description') }}
    // resources/js/pages/Welcome.vue:380
    'helper.title' => 'Contexte vérifié',
    // <h2 class="text-lg font-semibold">{{ $t('form_section.title') }}</h2> <p class="text-sm text-muted-foreground"> {{ $t(KEY) }} </p> </div> <div class="flex flex-wrap gap-2">
    // resources/js/pages/Welcome.vue:212
    'subtitle' => 'Les courtes étiquettes ci-dessous dépendent du contexte, pas de la signification littérale.',
    // flex-col gap-3 sm:flex-row sm:items-start sm:justify-between"> <div> <h2 class="text-lg font-semibold">{{ $t(KEY) }}</h2> <p class="text-sm text-muted-foreground"> {{ $t('form_section.subtitle') }} </p>
    // resources/js/pages/Welcome.vue:210
    'title' => 'Formulaire de vérification de traduction',
];
