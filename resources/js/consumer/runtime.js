import { createVoxController, normalizeLocale } from './shared.js';

export { trans, trans_choice, transChoice, wTrans, wTransChoice } from 'laravel-vue-i18n';

let runtimeEndpoint = '/vox/translations/{locale}';
let runtimeLocalesEndpoint = '/vox/locales';
let runtimeFetcher = (...args) => globalThis.fetch(...args);

function endpointForLocale(locale) {
    if (typeof runtimeEndpoint === 'function') {
        return runtimeEndpoint(locale);
    }

    if (runtimeEndpoint.includes('{locale}')) {
        return runtimeEndpoint.replace('{locale}', encodeURIComponent(locale));
    }

    return `${runtimeEndpoint.replace(/\/$/u, '')}/${encodeURIComponent(locale)}`;
}

async function loadMessages(locale) {
    const response = await runtimeFetcher(endpointForLocale(locale), {
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
    });

    if (!response.ok) {
        throw new Error(`Unable to load Laravel Vox translations for [${locale}] (${response.status}).`);
    }

    return response.json();
}

const controller = createVoxController(loadMessages);
export const availableVoxLocales = controller.availableLocales;
export const setVoxLocale = controller.setLocale;
export const useVox = controller.useVox;

/** Fetch the application's locale catalogue from Laravel Vox. */
export async function fetchVoxLocales(options = {}) {
    const fetcher = options.fetcher ?? runtimeFetcher;
    const endpoint =
        options.endpoint ??
        (options.baseUrl ? `${options.baseUrl.replace(/\/$/u, '')}/locales` : runtimeLocalesEndpoint);
    const response = await fetcher(endpoint, {
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
    });

    if (!response.ok) {
        throw new Error(`Unable to load the Laravel Vox locale catalogue (${response.status}).`);
    }

    const catalog = await response.json();
    const locales = Array.isArray(catalog.locales)
        ? catalog.locales
              .filter((locale) => locale && typeof locale.code === 'string')
              .map((locale) => ({ ...locale, code: normalizeLocale(locale.code) }))
        : [];
    const defaultLocale = normalizeLocale(
        typeof catalog.default_locale === 'string' ? catalog.default_locale : (locales[0]?.code ?? 'en')
    );

    controller.configureLocales(locales.map((locale) => locale.code));

    return { ...catalog, default_locale: defaultLocale, locales };
}

function configureRuntime(options) {
    const baseUrl = (options.baseUrl ?? '/vox').replace(/\/$/u, '');
    runtimeEndpoint = options.endpoint ?? `${baseUrl}/translations/{locale}`;
    runtimeLocalesEndpoint = options.localesEndpoint ?? `${baseUrl}/locales`;
    runtimeFetcher = options.fetcher ?? ((...args) => globalThis.fetch(...args));
}

/** Legacy synchronous plugin; use createVox to await catalogue discovery. */
export function createVoxI18n(options = {}) {
    configureRuntime(options);
    controller.configureLocales(
        options.locales ??
            [options.locale, globalThis.document?.documentElement.lang, options.fallbackLocale ?? 'en']
                .flat()
                .filter(Boolean)
    );

    if (options.locales === undefined) {
        void fetchVoxLocales()
            .then((catalog) => options.onLocalesLoad?.(catalog))
            .catch((error) => options.onLocalesError?.(error));
    }

    return controller.plugin(controller.configure(options));
}

/** Discover supported locales and prepare translations before mounting. */
export async function createVox(options = {}) {
    configureRuntime(options);
    let fallbackLocale = options.fallbackLocale;

    if (options.locales === undefined) {
        let catalog;
        try {
            catalog = await fetchVoxLocales();
        } catch (error) {
            options.onLocalesError?.(error);
            throw error;
        }
        fallbackLocale ??= catalog.default_locale;
        options.onLocalesLoad?.(catalog);
    } else {
        controller.configureLocales(options.locales);
    }

    return controller.initialize(controller.configure({ ...options, fallbackLocale }));
}

export default createVoxI18n;
