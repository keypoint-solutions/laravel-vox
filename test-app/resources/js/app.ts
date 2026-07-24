import '../css/app.css';

import { createVoxI18n } from '@laravel-vox/vue.js';
import { createApp } from 'vue';

import Welcome from './pages/Welcome.vue';

createApp(Welcome).use(createVoxI18n()).mount('#app');
