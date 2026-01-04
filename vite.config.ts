import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';
import laravel from 'laravel-vite-plugin';
import { defineConfig } from 'vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/js/vox.ts'],
            buildDirectory: 'vendor/vox',
            refresh: [
                'resources/css/**',
                'resources/js/**',
                'resources/views/**',
                'routes/**',
                'src/**',
                'config/**',
            ],
        }),
        tailwindcss(),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
    ],
    server: {
        watch: {
            ignored: [
                '**/test-app/**',
                '**/node_modules/**',
                '**/vendor/**',
                '**/.git/**',
            ],
        },
    },
});
