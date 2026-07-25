<?php

use KeypointSolutions\LaravelVox\Translation\FrontendTranslationArtifacts;

beforeEach(function (): void {
    app(FrontendTranslationArtifacts::class)->publish();
});

it('renders Laravel translations in Blade for every demo locale', function (): void {
    visit('/en/blade')
        ->assertSee('Blade consumer')
        ->assertSeeIn('[data-test="php-translation"]', 'Works.')
        ->assertSeeIn('[data-test="json-translation"]', 'This ends up in JSON')
        ->assertSeeIn('[data-test="parameter-translation"]', 'Today is 24 July 2026')
        ->assertSeeIn('[data-test="nested-translation"]', 'Dynamic Label 1')
        ->assertSeeIn('[data-test="dynamic-translation"]', 'Dynamic Label 1: Label 1 value')
        ->assertSeeIn('[data-test="choice-translation"]', '2 items selected')
        ->assertSeeIn('[data-test="choice-translation-directive"]', '1 item selected')
        ->assertSeeIn('[data-test="choice-translation-facade"]', 'No items selected')
        ->assertSeeIn('[data-test="choice-translation-instance"]', '3 items selected')
        ->assertNoJavaScriptErrors();

    visit('/fr/blade')
        ->assertSee('Traduction régulière')
        ->assertSeeIn('[data-test="php-translation"]', 'Fonctionne.')
        ->assertSeeIn('[data-test="json-translation"]', 'Cela se termine en JSON')
        ->assertSeeIn('[data-test="parameter-translation"]', "Aujourd'hui, c'est 24 July 2026")
        ->assertSeeIn('[data-test="dynamic-translation"]', "Étiquette dynamique 1: Valeur de l'étiquette 1")
        ->assertSeeIn('[data-test="choice-translation"]', '2 éléments sélectionnés')
        ->assertNoJavaScriptErrors();

    visit('/ro/blade')
        ->assertSee('Traducere regulată')
        ->assertSeeIn('[data-test="php-translation"]', 'Funcționează.')
        ->assertSeeIn('[data-test="json-translation"]', 'Acest lucru se termină în JSON')
        ->assertSeeIn('[data-test="parameter-translation"]', 'Astăzi este 24 July 2026')
        ->assertSeeIn('[data-test="dynamic-translation"]', 'Etichetă Dinamică 1: Valoare etichetă 1')
        ->assertSeeIn('[data-test="choice-translation"]', '2 elemente selectate')
        ->assertNoJavaScriptErrors();

    visit('/und/blade')
        ->assertSeeIn('[data-test="fallback-notice"]', 'Intentional fallback demo')
        ->assertSeeIn('[data-test="fallback-notice"]', 'falls back to en')
        ->assertSeeIn('[data-test="php-translation"]', 'Works.')
        ->assertNoJavaScriptErrors();
});

it('loads filtered Laravel translations from the runtime endpoint through the public Vue integration', function (): void {
    visit('/fr/vue')
        ->assertSee('Vue consumer')
        ->assertSee('Traduction régulière')
        ->assertSeeIn('[data-test="php-translation"]', 'Fonctionne.')
        ->assertSeeIn('[data-test="json-translation"]', 'Cela se termine en JSON')
        ->assertSeeIn('[data-test="parameter-translation"]', "Aujourd'hui, c'est 24 July 2026")
        ->assertSeeIn('[data-test="nested-translation"]', 'Étiquette dynamique 1')
        ->assertSeeIn('[data-test="dynamic-translation"]', "Étiquette dynamique 1: Valeur de l'étiquette 1")
        ->assertSeeIn('[data-test="choice-translation"]', '2 éléments sélectionnés')
        ->assertSeeIn('[data-test="choice-translation-alias"]', '1 élément sélectionné')
        ->assertSeeIn('[data-test="choice-translation-global"]', 'Aucun élément sélectionné')
        ->assertSeeIn('[data-test="choice-translation-reactive"]', '3 éléments sélectionnés')
        ->assertSeeIn('[data-test="backend-only-translation"]', 'validation.accepted')
        ->assertNoJavaScriptErrors();

    visit('/ro/vue')
        ->assertSee('Traducere regulată')
        ->assertSeeIn('[data-test="php-translation"]', 'Funcționează.')
        ->assertSeeIn('[data-test="json-translation"]', 'Acest lucru se termină în JSON')
        ->assertSeeIn('[data-test="parameter-translation"]', 'Astăzi este 24 July 2026')
        ->assertSeeIn('[data-test="dynamic-translation"]', 'Etichetă Dinamică 1: Valoare etichetă 1')
        ->assertSeeIn('[data-test="choice-translation"]', '2 elemente selectate')
        ->assertSeeIn('[data-test="backend-only-translation"]', 'validation.accepted')
        ->assertNoJavaScriptErrors();

    visit('/und/vue')
        ->assertSeeIn('[data-test="fallback-notice"]', 'Intentional fallback demo')
        ->assertSeeIn('[data-test="fallback-notice"]', 'plugin loads en')
        ->assertSeeIn('[data-test="php-translation"]', 'Works.')
        ->assertNoJavaScriptErrors();
});
