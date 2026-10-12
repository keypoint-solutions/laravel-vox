import type { Page } from '@inertiajs/core';

/** Read the initial page emitted by either supported Laravel Inertia adapter. */
export function readInitialPage(document: Pick<Document, 'getElementById' | 'querySelector'>): Page {
    const root = document.getElementById('app');
    const script = document.querySelector<HTMLScriptElement>('script[data-page="app"][type="application/json"]');
    const serializedPage = script?.textContent ?? root?.dataset.page;

    if (!serializedPage) {
        throw new Error('Vox initial page data is missing.');
    }

    return JSON.parse(serializedPage) as Page;
}
