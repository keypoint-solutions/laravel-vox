import '../css/app.css';

import {createApp} from 'vue';
import App from './App.vue';
import {initializeTheme} from './composables/useAppearance';
import router from './router';
import {i18nVue} from 'laravel-vue-i18n';

initializeTheme();

const globals = window as Window & { voxLocales?: string[]; voxLocale?: string };
const supportedLocales = globals.voxLocales ?? ['en'];
const pathLocale = window.location.pathname.split('/')[1] ?? '';
const htmlLocale = document.documentElement.lang.split('-')[0] ?? '';
const initialLocale = [pathLocale, globals.voxLocale, htmlLocale, 'en']
    .find((locale) => locale !== undefined && supportedLocales.includes(locale)) ?? 'en';

document.documentElement.lang = initialLocale;

createApp(App)
    .use(router)
    .use(i18nVue, {
        lang: initialLocale,
        resolve: async (lang: string) => {
            const langs = import.meta.glob('../../lang/*.json');

            if (!langs[`../../lang/${lang}.json`]) {
                return [];
            }

            return await langs[`../../lang/${lang}.json`]();
        },
        onLoad: (lang: string) => {
            document.documentElement.lang = lang.replace('_', '-');
        },
    })
    .mount('#app');

// This will set light / dark mode on page load...
initializeTheme();
