import '../css/app.css';

import { createVox } from '@laravel-vox/runtime.js';
import { createApp } from 'vue';

import Welcome from './pages/Welcome.vue';

async function bootstrap(): Promise<void> {
    const vox = await createVox();

    createApp(Welcome).use(vox).mount('#app');
}

void bootstrap();
