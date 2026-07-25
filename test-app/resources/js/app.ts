import '../css/app.css';

import { createVoxI18n } from '@laravel-vox/runtime.js';
import { createApp } from 'vue';

import Welcome from './pages/Welcome.vue';

createApp(Welcome)
    .use(
        createVoxI18n({
            locales: ['en', 'fr', 'ro'],
        })
    )
    .mount('#app');
