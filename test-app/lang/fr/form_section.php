<?php

// @formatter:off
// phpcs:disable
// phpcs:ignoreFile
// phpstan-ignore-file
// psalm-disable-file

return [
    // <p class="text-muted-foreground"> {{ $t(KEY, { from: '9:00', to: '17:00',
    // resources/js/pages/Welcome.vue:448
    'Move from :from to :to on :on for :for' => 'Déplacez de :from à :to le :on pour :for',
    // <p class="text-xs text-muted-foreground"> {{ $t(KEY, { and: '#', to: '42',
    // resources/js/pages/Welcome.vue:389
    'Use :and to reach :to in :in' => 'Utilisez :and pour atteindre :to dans :in',
    // border border-input px-4 py-2 text-sm font-medium text-foreground transition hover:bg-muted" type="button" > {{ $t(KEY) }} </button> <button class="inline-flex items-center justify-center rounded-md border border-input px-4 py-2 text-sm
    // resources/js/pages/Welcome.vue:245
    'actions.back' => 'Retour',
    // border border-input px-4 py-2 text-sm font-medium text-foreground transition hover:bg-muted" type="button" > {{ $t(KEY) }} </button> <button class="inline-flex items-center justify-center rounded-md bg-primary px-4 py-2 text-sm
    // resources/js/pages/Welcome.vue:251
    'actions.cancel' => 'Annuler',
    // px-4 py-2 text-sm font-medium text-primary-foreground transition hover:bg-primary/90" type="button" > {{ $t(KEY) }} </button> </div> </div>
    // resources/js/pages/Welcome.vue:257
    'actions.save' => 'Enregistrer',
    // px-4 py-2 text-sm font-medium text-primary-foreground transition hover:bg-primary/90" type="button" > {{ $t(KEY) }} </button> </div> </div>
    // resources/js/pages/Welcome.vue:471
    'actions.save_and_close' => 'Enregistrer & fermer',
    // border border-input px-4 py-2 text-sm font-medium text-foreground transition hover:bg-muted" type="button" > {{ $t(KEY) }} </button> <button class="inline-flex items-center justify-center rounded-md bg-primary px-4 py-2 text-sm
    // resources/js/pages/Welcome.vue:465
    'actions.save_draft' => 'Enregistrer le brouillon',
    // " /> <p class="text-xs text-muted-foreground"> {{ $t(KEY) }} </p> </div>
    // resources/js/pages/Welcome.vue:331
    'fields.amount.helper' => 'Charge est un nom ici, pas une action.',
    // <div class="flex flex-col gap-2"> <label class="text-sm font-medium" for="form-amount"> {{ $t(KEY) }} </label> <input id="form-amount"
    // resources/js/pages/Welcome.vue:319
    'fields.amount.label' => 'Montant à facturer',
    // type="text" inputmode="decimal" :placeholder=" $t(KEY) " /> <p class="text-xs text-muted-foreground">
    // resources/js/pages/Welcome.vue:327
    'fields.amount.placeholder' => '125,00',
    // type="checkbox" /> <label class="text-sm" for="form-consent"> {{ $t(KEY) }} </label> </div> </form>
    // resources/js/pages/Welcome.vue:431
    'fields.consent.label' => 'J\'accepte les conditions de traitement.',
    // class="text-sm font-medium" for="form-display-name" > {{ $t(KEY) }} </label> <input id="form-display-name"
    // resources/js/pages/Welcome.vue:285
    'fields.display_name.label' => 'Nom affiché',
    // type="text" :placeholder=" $t(KEY, ) " />
    // resources/js/pages/Welcome.vue:293
    'fields.display_name.placeholder' => 'Nom sur le profil public',
    // " /> <p class="text-xs text-muted-foreground"> {{ $t(KEY) }} </p> </div>
    // resources/js/pages/Welcome.vue:313
    'fields.email.helper' => 'Nous ne partageons jamais cette adresse.',
    // <div class="flex flex-col gap-2"> <label class="text-sm font-medium" for="form-email"> {{ $t(KEY) }} </label> <input id="form-email"
    // resources/js/pages/Welcome.vue:301
    'fields.email.label' => 'Email pour les reçus',
    // type="email" inputmode="email" :placeholder=" $t(KEY) " /> <p class="text-xs text-muted-foreground">
    // resources/js/pages/Welcome.vue:309
    'fields.email.placeholder' => 'nom@entreprise.com',
    // " /> <p class="text-xs text-muted-foreground"> {{ $t(KEY) }} </p> </div>
    // resources/js/pages/Welcome.vue:276
    'fields.full_name.helper' => 'Utilisez le nom qui doit apparaître sur la facture.',
    // sm:grid-cols-2"> <div class="flex flex-col gap-2"> <label class="text-sm font-medium" for="form-full-name"> {{ $t(KEY) }} </label> <input id="form-full-name"
    // resources/js/pages/Welcome.vue:265
    'fields.full_name.label' => 'Nom complet',
    // class="h-10 rounded-md border border-input bg-background px-3 text-sm" type="text" :placeholder=" $t(KEY) " /> <p class="text-xs text-muted-foreground">
    // resources/js/pages/Welcome.vue:272
    'fields.full_name.placeholder' => 'par ex. Alex Rivera',
    // <div class="flex flex-col gap-2 sm:col-span-2"> <label class="text-sm font-medium" for="form-notes"> {{ $t(KEY) }} </label> <textarea id="form-notes"
    // resources/js/pages/Welcome.vue:402
    'fields.notes.label' => 'Notes internes',
    // id="form-notes" class="min-h-[96px] rounded-md border border-input bg-background px-3 py-2 text-sm" :placeholder=" $t(KEY) " ></textarea> </div>
    // resources/js/pages/Welcome.vue:408
    'fields.notes.placeholder' => 'Ces notes sont uniquement destinées à votre équipe.',
    // <div class="flex flex-col gap-2"> <label class="text-sm font-medium" for="form-reference"> {{ $t(KEY) }} </label> <input id="form-reference"
    // resources/js/pages/Welcome.vue:376
    'fields.reference.label' => 'Code de référence',
    // class="h-10 rounded-md border border-input bg-background px-3 text-sm" type="text" :placeholder=" $t(KEY) " /> <p class="text-xs text-muted-foreground">
    // resources/js/pages/Welcome.vue:383
    'fields.reference.placeholder' => 'INV-2025-0042',
    // type="checkbox" /> <label class="text-sm" for="form-shipping"> {{ $t(KEY) }} </label> </div>
    // resources/js/pages/Welcome.vue:420
    'fields.shipping_same.label' => 'L\'adresse de livraison est la même que celle de facturation.',
    // <div class="flex flex-col gap-2"> <label class="text-sm font-medium" for="form-status"> {{ $t(KEY) }} </label> <select id="form-status"
    // resources/js/pages/Welcome.vue:337
    'fields.status.label' => 'Statut',
    // <option value="draft"> {{ $t(KEY, ) }} </option>
    // resources/js/pages/Welcome.vue:346
    'fields.status.options.draft' => 'Brouillon',
    // <option value="due-now"> {{ $t(KEY, ) }} </option>
    // resources/js/pages/Welcome.vue:360
    'fields.status.options.due_now' => 'À payer maintenant',
    // <option value="on-hold"> {{ $t(KEY, ) }} </option>
    // resources/js/pages/Welcome.vue:353
    'fields.status.options.on_hold' => 'En attente',
    // <option value="paid"> {{ $t(KEY, ) }} </option>
    // resources/js/pages/Welcome.vue:367
    'fields.status.options.paid' => 'Payé',
    // {{ $t('form_section.helper.title') }} </p> <p class="text-muted-foreground"> {{ $t(KEY) }} </p> <p class="text-muted-foreground"> {{
    // resources/js/pages/Welcome.vue:443
    'helper.description' => '« Enregistrer » sauvegarde les modifications, tandis que « Enregistrer le brouillon » les garde privées.',
    // flex-col gap-2 rounded-lg border border-dashed border-input bg-muted/40 p-4 text-sm" > <p class="font-medium"> {{ $t(KEY) }} </p> <p class="text-muted-foreground"> {{ $t('form_section.helper.description') }}
    // resources/js/pages/Welcome.vue:440
    'helper.title' => 'Vérification du contexte',
    // {{ $t('form_section.title') }} </h2> <p class="text-sm text-muted-foreground"> {{ $t(KEY) }} </p> </div> <div class="flex flex-wrap gap-2">
    // resources/js/pages/Welcome.vue:237
    'subtitle' => 'Les étiquettes courtes ci-dessous dépendent du contexte, pas du sens littéral.',
    // > <div> <h2 class="text-lg font-semibold"> {{ $t(KEY) }} </h2> <p class="text-sm text-muted-foreground"> {{ $t('form_section.subtitle') }}
    // resources/js/pages/Welcome.vue:234
    'title' => 'Formulaire de pointage de traduction',
];
