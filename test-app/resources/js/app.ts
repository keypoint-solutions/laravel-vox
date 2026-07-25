import '../css/app.css';

import { createVoxI18n, fetchVoxLocales } from '@laravel-vox/runtime.js';
import { createApp } from 'vue';

import Welcome from './pages/Welcome.vue';

async function bootstrap(): Promise<void> {
    const catalog = await fetchVoxLocales();

    createApp(Welcome)
        .use(
            createVoxI18n({
                fallbackLocale: catalog.default_locale,
                locales: catalog.locales.map((locale) => locale.code),
            })
        )
        .mount('#app');
}

void bootstrap();
