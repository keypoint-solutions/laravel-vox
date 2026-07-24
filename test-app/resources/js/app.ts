import '../css/app.css';

import { createVoxI18n } from '@keypoint-solutions/laravel-vox/vue';
import { createApp } from 'vue';

import Welcome from './pages/Welcome.vue';

createApp(Welcome).use(createVoxI18n()).mount('#app');
