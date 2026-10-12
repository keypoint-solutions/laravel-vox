import assert from 'node:assert/strict';
import { test } from 'node:test';

import { readInitialPage } from '../../resources/js/lib/initialPage.ts';

const page = {
    component: 'Manage',
    props: { errors: {}, translations: { data: [] }, title: 'Quotes " & <tags> — Bonjour' },
    url: '/vox/manage?search=hello',
    version: 'vox-assets',
};

function documentWithPage({ scriptPage, attributePage } = {}) {
    return {
        getElementById(id) {
            assert.equal(id, 'app');
            return { dataset: { page: attributePage } };
        },
        querySelector(selector) {
            assert.equal(selector, 'script[data-page="app"][type="application/json"]');
            return scriptPage === undefined ? null : { textContent: scriptPage };
        },
    };
}

test('boots with the default Inertia 2 attribute payload', () => {
    assert.deepEqual(readInitialPage(documentWithPage({ attributePage: JSON.stringify(page) })), page);
});

test('boots with the Inertia 3 script payload', () => {
    assert.deepEqual(readInitialPage(documentWithPage({ scriptPage: JSON.stringify(page) })), page);
});

test('prefers the script payload when a legacy attribute is also present', () => {
    assert.deepEqual(readInitialPage(documentWithPage({
        scriptPage: JSON.stringify(page),
        attributePage: JSON.stringify({ ...page, component: 'Dashboard' }),
    })), page);
});

test('reports missing initial page data', () => {
    assert.throws(() => readInitialPage(documentWithPage()), /Vox initial page data is missing/);
});

test('rejects malformed page data instead of silently mounting another page', () => {
    assert.throws(() => readInitialPage(documentWithPage({ scriptPage: '{broken' })), SyntaxError);
});
