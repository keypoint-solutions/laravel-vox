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
        ->assertNoJavaScriptErrors();

    visit('/fr/blade')
        ->assertSee('Traduction régulière')
        ->assertSeeIn('[data-test="php-translation"]', 'Fonctionne.')
        ->assertSeeIn('[data-test="json-translation"]', 'Cela se termine en JSON')
        ->assertNoJavaScriptErrors();

    visit('/ro/blade')
        ->assertSee('Traducere regulată')
        ->assertSeeIn('[data-test="php-translation"]', 'Funcționează.')
        ->assertNoJavaScriptErrors();
});

it('loads filtered Laravel translations from the runtime endpoint through the public Vue integration', function (): void {
    visit('/fr/vue')
        ->assertSee('Vue consumer')
        ->assertSee('Traduction régulière')
        ->assertSeeIn('[data-test="php-translation"]', 'Fonctionne.')
        ->assertSeeIn('[data-test="json-translation"]', 'Cela se termine en JSON')
        ->assertSeeIn('[data-test="parameter-translation"]', "Aujourd'hui, c'est 24 July 2026")
        ->assertSeeIn('[data-test="dynamic-translation"]', "Étiquette dynamique 1: Valeur de l'étiquette 1")
        ->assertSeeIn('[data-test="backend-only-translation"]', 'validation.accepted')
        ->assertNoJavaScriptErrors();
});
