import { fileURLToPath, URL } from 'node:url';

import vue from '@vitejs/plugin-vue';
import tailwindcss from '@tailwindcss/vite';
import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    resolve: {
        alias: {
            '@laravel-vox': fileURLToPath(
                new URL('./vendor/keypoint-solutions/laravel-vox/resources/js/consumer', import.meta.url)
            ),
            '@laravel-vox/runtime.js': fileURLToPath(
                new URL('./vendor/keypoint-solutions/laravel-vox/resources/js/consumer/runtime.js', import.meta.url)
            ),
        },
    },
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.ts'],
            refresh: true,
        }),
        tailwindcss(),
        vue(),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
