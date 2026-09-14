import assert from 'node:assert/strict';
import { registerHooks } from 'node:module';
import { test, beforeEach } from 'node:test';
import { fileURLToPath } from 'node:url';

const element = { lang: 'en', setAttribute(name, value) { this[name] = value; } };
globalThis.document = { documentElement: element, createElement() { return {}; } };
globalThis.window = {};

const { createApp, isReadonly, watchEffect, nextTick } = await import('vue');
const { reset } = await import('laravel-vue-i18n');
const runtime = await import('../resources/js/consumer/runtime.js');
const { resolveLocale } = await import('../resources/js/consumer/shared.js');
const { default: vox } = await import('../resources/js/consumer/vite.js');
const { resolveConfig } = await import('vite');
const virtualModule = 'data:text/javascript,' + encodeURIComponent(`
    export const availableVoxLocales = ['en', 'fr', 'ro'];
    export async function loadVoxLocale(locale) {
        return { default: locale.startsWith('php_') ? { 'frontend.title': locale.slice(4) } : { Hello: locale } };
    }
`);
const hooks = registerHooks({
    resolve(specifier, context, nextResolve) {
        return specifier === 'virtual:laravel-vox/translations'
            ? { url: virtualModule, shortCircuit: true }
            : nextResolve(specifier, context);
    },
});
const bundled = await import('../resources/js/consumer/vue.js');
hooks.deregister();

beforeEach(() => {
    reset();
    element.lang = 'en';
    delete globalThis.localStorage;
    delete globalThis.sessionStorage;
});

function server({ defaultLocale = 'en', failCatalogue = false, failTranslations = false } = {}) {
    const requests = [];
    const fetcher = async (url, options) => {
        requests.push(url);
        assert.equal(options.credentials, 'same-origin');
        const catalogue = url.endsWith('/locales');
        return {
            ok: !(catalogue ? failCatalogue : failTranslations),
            status: 503,
            async json() {
                return catalogue
                    ? { default_locale: defaultLocale, locales: ['en', 'fr', 'ro'].map(code => ({ code })) }
                    : { Hello: url.split('/').at(-1), Items: 'one item|:count items' };
            },
        };
    };
    return { fetcher, requests };
}

test('preferences match in order, normalize case and progressively fall back', () => {
    assert.equal(resolveLocale(['fr-CA', 'ro'], ['en', 'fr', 'ro'], 'en'), 'fr');
    assert.equal(resolveLocale(['de-DE', 'ro'], ['en', 'fr', 'ro'], 'en'), 'ro');
    assert.equal(resolveLocale('PT-br', ['pt_BR', 'en'], 'en'), 'pt_BR');
    assert.equal(resolveLocale('zh-Hant-TW', ['zh_Hant', 'zh', 'en'], 'en'), 'zh_Hant');
    element.lang = 'fr';
    assert.equal(resolveLocale('de', ['en', 'fr'], 'en'), 'fr');
    element.lang = 'und';
    assert.equal(resolveLocale([], ['ro', 'fr'], 'fr'), 'fr');
});

test('runtime bootstrap discovers catalogue and has translations ready before mount', async () => {
    const { fetcher, requests } = server();
    const plugin = await runtime.createVox({ locale: ['fr-CA', 'ro'], fetcher });
    assert.equal(runtime.trans('Hello'), 'fr');
    const app = createApp({});
    app.use(plugin);
    assert.equal(app.config.globalProperties.$t('Hello'), 'fr');
    assert.equal(runtime.transChoice('Items', 2), '2 items');
    assert.equal(element.lang, 'fr');
    assert.deepEqual(requests, ['/vox/locales', '/vox/translations/fr']);
});

test('catalogue default is used after unavailable browser and document locales', async () => {
    element.lang = 'und';
    const { fetcher, requests } = server({ defaultLocale: 'ro' });
    await runtime.createVox({ locale: ['de-DE'], fetcher });
    assert.equal(runtime.trans('Hello'), 'ro');
    assert.deepEqual(requests, ['/vox/locales', '/vox/translations/ro']);
});

test('explicit fallback overrides catalogue default', async () => {
    element.lang = 'und';
    await runtime.createVox({ ...server({ defaultLocale: 'ro' }), fallbackLocale: 'fr' });
    assert.equal(runtime.trans('Hello'), 'fr');
});

test('custom base URL and endpoint overrides are respected', async () => {
    const { fetcher, requests } = server();
    await runtime.createVox({ baseUrl: '/admin/i18n/', fetcher });
    assert.deepEqual(requests, ['/admin/i18n/locales', '/admin/i18n/translations/en']);
    reset();
    requests.length = 0;
    await runtime.createVox({ baseUrl: '/unused', localesEndpoint: '/custom/locales', endpoint: locale => `/messages/${locale}`, fetcher });
    assert.deepEqual(requests, ['/custom/locales', '/messages/en']);
});

test('explicit locales skip discovery and switching updates readonly reactive state', async () => {
    const { fetcher, requests } = server();
    const state = runtime.useVox();
    const plugin = await runtime.createVox({ locales: ['en', 'fr', 'ro'], fetcher });
    createApp({}).use(plugin);
    const observed = [];
    const stop = watchEffect(() => observed.push(state.locale.value));
    await state.setLocale(['de-DE', 'ro-RO']);
    await nextTick();
    assert.equal(runtime.trans('Hello'), 'ro');
    assert.equal(element.lang, 'ro');
    assert.deepEqual(observed, ['en', 'ro']);
    assert.deepEqual([...state.locales.value], ['en', 'fr', 'ro']);
    assert.ok(isReadonly(state.locale));
    assert.ok(isReadonly(state.locales.value));
    assert.deepEqual(requests, ['/vox/translations/en', '/vox/translations/ro']);
    stop();
});

test('failed catalogue and translation loads reject initialization', async () => {
    let reported;
    await assert.rejects(runtime.createVox({ ...server({ failCatalogue: true }), onLocalesError: error => { reported = error; } }), /catalogue/);
    assert.match(reported.message, /503/);
    await assert.rejects(runtime.createVox({ ...server({ failTranslations: true }) }), /translations/);
});

test('failed switching keeps the active locale', async () => {
    const { fetcher } = server();
    let fail = false;
    await runtime.createVox({ fetcher: (...args) => fail ? Promise.reject(new Error('offline')) : fetcher(...args) });
    fail = true;
    await assert.rejects(runtime.setVoxLocale('ro'), /offline/);
    assert.equal(runtime.useVox().locale.value, 'en');
    assert.equal(runtime.trans('Hello'), 'en');
});

test('legacy synchronous runtime plugin remains usable', async () => {
    const { fetcher } = server();
    const plugin = runtime.createVoxI18n({ locales: ['en', 'ro'], locale: 'ro', fetcher });
    createApp({}).use(plugin);
    await runtime.setVoxLocale('ro');
    assert.equal(runtime.trans('Hello'), 'ro');
});

test('bundled initialization and composable share the same locale behavior', async () => {
    const plugin = await bundled.createVox({ locale: ['fr-CA', 'ro'] });
    const app = createApp({});
    app.use(plugin);
    assert.equal(app.config.globalProperties.$t('frontend.title'), 'fr');
    assert.equal(bundled.trans('Hello'), 'fr');
    await bundled.useVox().setLocale('ro-RO');
    assert.equal(bundled.trans('frontend.title'), 'ro');
    assert.equal(bundled.useVox().locale.value, 'ro');
});

test('Vite runtime mode registers automatic alias without translation bundling', async () => {
    const plugins = vox({ runtime: true });
    assert.equal(plugins.some(plugin => plugin.name === 'laravel-vox-translations'), false);
    const config = await resolveConfig({ configFile: false, plugins }, 'serve');
    const alias = config.resolve.alias.find(alias => alias.find === '@laravel-vox');
    assert.equal(alias.replacement, fileURLToPath(new URL('../resources/js/consumer/', import.meta.url)));
    assert.ok(config.resolve.dedupe.includes('vue'));
    assert.ok(config.resolve.dedupe.includes('laravel-vue-i18n'));
});

test('an empty locale loads the configured fallback before initialization finishes', async () => {
    const { fetcher } = server();
    await runtime.createVox({
        locale: 'fr',
        fallbackLocale: 'ro',
        fetcher: (url, options) => url.endsWith('/fr')
            ? Promise.resolve({ ok: true, json: async () => ({}) })
            : fetcher(url, options),
    });
    assert.equal(runtime.trans('Hello'), 'ro');
    assert.equal(runtime.useVox().locale.value, 'ro');
});

test('the last requested locale wins when earlier downloads finish later', async () => {
    const { fetcher } = server();
    let releaseFrench;
    await runtime.createVox({
        fetcher: (url, options) => url.endsWith('/fr')
            ? new Promise(resolve => { releaseFrench = () => resolve(fetcher(url, options)); })
            : fetcher(url, options),
    });
    const earlier = runtime.setVoxLocale('fr');
    await runtime.setVoxLocale('ro');
    releaseFrench();
    await earlier;
    assert.equal(runtime.useVox().locale.value, 'ro');
    assert.equal(runtime.trans('Hello'), 'ro');
});

function memoryStorage(initial = {}) {
    const values = new Map(Object.entries(initial));
    return {
        getItem: key => values.get(key) ?? null,
        setItem: (key, value) => values.set(key, value),
    };
}

for (const [persist, storageName] of [['local', 'localStorage'], ['session', 'sessionStorage']]) {
    test(`${persist} persistence restores before browser preferences and saves successful switches`, async () => {
        const storage = globalThis[storageName] = memoryStorage({ 'laravel-vox.locale': 'ro' });
        await runtime.createVox({ ...server(), locale: ['fr-CA'], persist });
        assert.equal(runtime.trans('Hello'), 'ro');
        await runtime.useVox().setLocale('fr-CA');
        assert.equal(storage.getItem('laravel-vox.locale'), 'fr');
    });
}

test('explicit locale overrides storage without replacing the saved preference', async () => {
    globalThis.localStorage = memoryStorage({ 'laravel-vox.locale': 'ro' });
    await runtime.createVox({ ...server(), locale: 'fr', persist: 'local' });
    assert.equal(runtime.trans('Hello'), 'fr');
    assert.equal(localStorage.getItem('laravel-vox.locale'), 'ro');
});

test('unsupported stored locale falls through to ordered preferences', async () => {
    globalThis.localStorage = memoryStorage({ 'laravel-vox.locale': 'de' });
    await runtime.createVox({ ...server(), locale: ['fr-CA'], persist: 'local' });
    assert.equal(runtime.trans('Hello'), 'fr');
});

test('detection is never persisted and persistence is disabled by default', async () => {
    globalThis.localStorage = memoryStorage();
    await runtime.createVox({ ...server(), locale: ['fr'], persist: 'local' });
    assert.equal(localStorage.getItem('laravel-vox.locale'), null);
    reset();
    element.lang = 'en';
    localStorage.setItem('laravel-vox.locale', 'ro');
    await runtime.createVox({ ...server() });
    assert.equal(runtime.trans('Hello'), 'en');
    await runtime.setVoxLocale('fr');
    assert.equal(localStorage.getItem('laravel-vox.locale'), 'ro');
});

test('bundled translations support a custom storage key and session isolation', async () => {
    globalThis.sessionStorage = memoryStorage({ 'custom.locale': 'ro' });
    globalThis.localStorage = memoryStorage({ 'custom.locale': 'en' });
    await bundled.createVox({ persist: 'session', storageKey: 'custom.locale' });
    assert.equal(bundled.trans('Hello'), 'ro');
    await bundled.setVoxLocale('fr');
    assert.equal(sessionStorage.getItem('custom.locale'), 'fr');
    assert.equal(sessionStorage.getItem('laravel-vox.locale'), null);
    assert.equal(localStorage.getItem('custom.locale'), 'en');
});

test('storage getter and write errors do not break translations', async () => {
    Object.defineProperty(globalThis, 'localStorage', {
        configurable: true,
        get() { throw new Error('access denied'); },
    });
    await runtime.createVox({ ...server(), persist: 'local' });
    await runtime.setVoxLocale('ro');
    assert.equal(runtime.trans('Hello'), 'ro');
    delete globalThis.localStorage;
    globalThis.localStorage = { getItem: () => null, setItem() { throw new Error('quota exceeded'); } };
    await runtime.setVoxLocale('fr');
    assert.equal(runtime.trans('Hello'), 'fr');
});

test('failed switches do not overwrite the saved preference', async () => {
    globalThis.localStorage = memoryStorage({ 'laravel-vox.locale': 'en' });
    const { fetcher } = server();
    await runtime.createVox({ persist: 'local', fetcher: (url, options) => url.endsWith('/ro')
        ? Promise.reject(new Error('offline')) : fetcher(url, options) });
    await assert.rejects(runtime.setVoxLocale('ro'), /offline/);
    assert.equal(localStorage.getItem('laravel-vox.locale'), 'en');
});

test('late switches cannot overwrite the latest stored choice', async () => {
    globalThis.localStorage = memoryStorage();
    const { fetcher } = server();
    let release;
    await runtime.createVox({ persist: 'local', fetcher: (url, options) => url.endsWith('/fr')
        ? new Promise(resolve => { release = () => resolve(fetcher(url, options)); }) : fetcher(url, options) });
    const pending = runtime.setVoxLocale('fr');
    await runtime.setVoxLocale('ro');
    release();
    await pending;
    assert.equal(localStorage.getItem('laravel-vox.locale'), 'ro');
});

test('dictionary refresh updates reactive text, deleted keys, and cached locale switches in place', async () => {
    const { createVoxController } = await import('../resources/js/consumer/shared.js');
    const { I18n, wTrans } = await import('laravel-vue-i18n');
    const translations = {
        en: { Greeting: 'Hello', Fallback: 'Fallback', Deleted: 'Remove me' },
        fr: { Greeting: 'Bonjour', Deleted: 'Remove me', Primary: 'Primary' },
    };
    const controller = createVoxController(async lang => ({ ...translations[lang] }));
    controller.configureLocales(['en', 'fr']);
    const plugin = await controller.initialize(controller.configure({ locale: 'fr' }));
    const app = createApp({});
    app.use(plugin);
    const instance = I18n.getSharedInstance();
    await new Promise(resolve => setTimeout(resolve, 0));
    const greeting = wTrans('Greeting');
    const fallback = wTrans('Fallback');
    const deleted = wTrans('Deleted');
    const primary = wTrans('Primary');
    assert.equal(greeting.value, 'Bonjour');
    assert.equal(deleted.value, 'Remove me');
    translations.en = { Greeting: 'Hi', Fallback: 'Updated fallback', Primary: 'Now from fallback' };
    translations.fr = { Greeting: 'Bonsoir' };
    await controller.refreshMessages();
    await nextTick();
    assert.equal(I18n.getSharedInstance(), instance);
    assert.equal(greeting.value, 'Bonsoir');
    assert.equal(fallback.value, 'Fallback');
    assert.equal(deleted.value, 'Deleted');
    assert.equal(primary.value, 'Primary');
    assert.equal(controller.useVox().locale.value, 'fr');
    assert.equal(app.config.globalProperties.$t('Greeting'), 'Bonsoir');
    await controller.setLocale('en');
    assert.equal(greeting.value, 'Hi');
    assert.equal(fallback.value, 'Updated fallback');
    assert.equal(deleted.value, 'Deleted');
    await controller.setLocale('fr');
    assert.equal(greeting.value, 'Bonsoir');
});

test('translation refresh preserves a mounted form and its entered value', async () => {
    const { createRenderer, h, ref, onMounted } = await import('vue');
    const { createVoxController } = await import('../resources/js/consumer/shared.js');
    const { wTrans } = await import('laravel-vue-i18n');
    let label = 'Title';
    let mounts = 0;
    let draft;
    const controller = createVoxController(async () => ({ Label: label }));
    controller.configureLocales(['en']);
    const plugin = await controller.initialize(controller.configure({ locale: 'en' }));
    const renderer = createRenderer({
        createElement: type => ({ type, children: [], props: {} }),
        insert: (child, parent) => { child.parent = parent; parent.children.push(child); },
        remove: child => { child.parent.children = child.parent.children.filter(node => node !== child); },
        setElementText: (node, text) => { node.text = text; },
        patchProp: (node, key, previous, value) => { node.props[key] = value; },
        parentNode: node => node.parent,
        nextSibling: () => null,
    });
    const root = { children: [] };
    const app = renderer.createApp({
        setup() {
            draft = ref('');
            const text = wTrans('Label');
            onMounted(() => mounts++);
            return () => h('form', [h('label', text.value), h('input', { value: draft.value })]);
        },
    });
    app.use(plugin);
    app.mount(root);
    try {
        draft.value = 'My unsaved event';
        await nextTick();
        const form = root.children[0];
        const input = form.children[1];
        label = 'Event title';
        await controller.refreshMessages();
        await nextTick();
        assert.equal(mounts, 1);
        assert.equal(root.children[0], form);
        assert.equal(form.children[1], input);
        assert.equal(input.props.value, 'My unsaved event');
        assert.equal(form.children[0].text, 'Event title');
    } finally {
        app.unmount();
    }
});

test('runtime HMR events update translations in place and unregister on disposal', async () => {
    const { registerRuntimeHotReload } = await import('../resources/js/consumer/runtime-hmr.js');
    const { createVoxController } = await import('../resources/js/consumer/shared.js');
    let wording = { Hello: 'Before', Removed: 'Old value' };
    const controller = createVoxController(async () => wording);
    controller.configureLocales(['en']);
    await controller.initialize(controller.configure({ locale: 'en' }));
    const reactiveText = runtime.wTrans('Hello');
    const handlers = new Map();
    let dispose;
    registerRuntimeHotReload({
        on(event, callback) { handlers.set(event, callback); },
        off(event) { handlers.delete(event); },
        dispose(callback) { dispose = callback; },
    }, () => controller.refreshMessages());
    wording = { Hello: 'After' };
    await handlers.get('vox:translations-updated')();
    await nextTick();
    assert.equal(reactiveText.value, 'After');
    assert.equal(runtime.trans('Removed'), 'Removed');
    dispose();
    assert.equal(handlers.size, 0);
});
