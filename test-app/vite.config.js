import vue from '@vitejs/plugin-vue';
import tailwindcss from '@tailwindcss/vite';
import vox from '@keypoint-solutions/laravel-vox/vite';
import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.ts'],
            refresh: true,
        }),
        tailwindcss(),
        vue(),
        vox({ groups: ['frontend', 'form_section'] }),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
