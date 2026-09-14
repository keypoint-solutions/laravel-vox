import { I18n, i18nVue, loadLanguageAsync } from 'laravel-vue-i18n';
import { computed, reactive, readonly, ref } from 'vue';

export function normalizeLocale(locale) {
    return locale.trim().replaceAll('-', '_');
}

export function resolveLocale(preferences, locales, fallbackLocale) {
    const candidates = [
        ...(Array.isArray(preferences) ? preferences : [preferences]),
        globalThis.document?.documentElement.lang,
        fallbackLocale,
        locales[0],
    ];

    for (const candidate of candidates) {
        if (typeof candidate !== 'string' || candidate.trim() === '') {
            continue;
        }

        const parts = normalizeLocale(candidate).split('_');

        while (parts.length > 0) {
            const match = locales.find((locale) => locale.toLowerCase() === parts.join('_').toLowerCase());

            if (match) {
                return match;
            }

            parts.pop();
        }
    }

    return fallbackLocale;
}

/** Shared state follows laravel-vue-i18n's single application runtime. */
export function createVoxController(loadMessages) {
    const availableLocales = reactive([]);
    const locale = ref('');
    let fallbackLocale = 'en';
    let options = {};
    let messages = new Map();
    let switchRequest = 0;

    function configureLocales(locales) {
        availableLocales.splice(0, availableLocales.length, ...new Set(locales.map(normalizeLocale).filter(Boolean)));
    }

    async function prepareLocale(selectedLocale) {
        if (!messages.has(selectedLocale)) {
            messages.set(selectedLocale, await loadMessages(selectedLocale));
        }
    }

    function translationOptions(selectedLocale) {
        return {
            lang: selectedLocale,
            fallbackLang: fallbackLocale,
            resolve: (requestedLocale) => {
                if (requestedLocale.startsWith('php_')) {
                    return {};
                }

                return messages.has(requestedLocale)
                    ? messages.get(requestedLocale)
                    : loadMessages(requestedLocale).then((translations) => ({ default: translations }));
            },
            onLoad: (loadedLocale) => {
                locale.value = loadedLocale;

                if (globalThis.document) {
                    document.documentElement.lang = loadedLocale.replaceAll('_', '-');
                }

                options.onLoad?.(loadedLocale);
            },
        };
    }

    function storage() {
        if (options.persist === 'local') {
            return globalThis.localStorage;
        }

        if (options.persist === 'session') {
            return globalThis.sessionStorage;
        }
    }

    function savedLocale() {
        try {
            return storage()?.getItem(options.storageKey ?? 'laravel-vox.locale');
        } catch {
            return null;
        }
    }

    function persistLocale() {
        try {
            storage()?.setItem(options.storageKey ?? 'laravel-vox.locale', locale.value);
        } catch {
            // Storage may be blocked or full; the active translation must remain usable.
        }
    }

    function configure(nextOptions) {
        options = nextOptions;
        fallbackLocale = normalizeLocale(options.fallbackLocale ?? 'en');
        messages = new Map();

        const preferences =
            typeof options.locale === 'string' ? options.locale : [savedLocale(), ...(options.locale ?? [])];

        return resolveLocale(preferences, availableLocales, fallbackLocale);
    }

    function plugin(selectedLocale) {
        return {
            install(app) {
                app.use(i18nVue, translationOptions(selectedLocale));
            },
        };
    }

    async function prepareSelection(selectedLocale) {
        await prepareLocale(selectedLocale);

        if (selectedLocale !== fallbackLocale && Object.keys(messages.get(selectedLocale)).length === 0) {
            await prepareLocale(fallbackLocale);

            return fallbackLocale;
        }

        return selectedLocale;
    }

    async function initialize(selectedLocale) {
        const preparedLocale = await prepareSelection(selectedLocale);
        const initializedOptions = translationOptions(preparedLocale);

        await new Promise((resolve) => {
            I18n.getSharedInstance(
                {
                    ...initializedOptions,
                    onLoad: (loadedLocale) => {
                        initializedOptions.onLoad(loadedLocale);
                        resolve();
                    },
                },
                true
            );
        });

        return plugin(preparedLocale);
    }

    async function setLocale(preferences) {
        const request = ++switchRequest;
        const selectedLocale = resolveLocale(preferences, availableLocales, fallbackLocale);
        const preparedLocale = await prepareSelection(selectedLocale);

        if (request === switchRequest) {
            await loadLanguageAsync(preparedLocale);

            if (request === switchRequest) {
                persistLocale();
            }
        }
    }

    /** Refresh loaded dictionaries in place without reinstalling the Vue plugin. */
    async function refreshMessages() {
        const instance = I18n.getSharedInstance();
        const loadedLocales = [...new Set([...messages.keys(), ...I18n.loaded.map(({ lang }) => lang)])];
        const refreshed = await Promise.all(loadedLocales.map(async (lang) => [lang, await loadMessages(lang)]));

        for (const [lang, translations] of refreshed) {
            messages.set(lang, translations);
            instance.addLoadedLang({ lang, messages: translations });
        }

        const activeLocale = instance.getActiveLanguage();
        if (messages.has(activeLocale)) {
            instance.setLanguage({ lang: activeLocale, messages: messages.get(activeLocale) });
        }
    }

    function useVox() {
        return {
            locale: readonly(locale),
            locales: computed(() => readonly(availableLocales)),
            setLocale,
        };
    }

    return { availableLocales, configureLocales, configure, plugin, initialize, setLocale, refreshMessages, useVox };
}
