import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';
import laravel from 'laravel-vite-plugin';
import { defineConfig } from 'vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/js/vox.ts'],
            publicDirectory: 'dist',
            buildDirectory: 'vox',
            hotFile: 'test-app/public/vendor/vox/hot',
            refresh: ['resources/css/**', 'resources/js/**', 'resources/views/**', 'routes/**', 'src/**', 'config/**'],
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
    // Consumers serve the build from public/vendor/vox, not the /vox/ base the Laravel plugin derives from
    // buildDirectory, so resolve chunk preloads relative to the importing module instead.
    experimental: {
        renderBuiltUrl: () => ({ relative: true }),
    },
    server: {
        watch: {
            ignored: ['**/test-app/**', '**/node_modules/**', '**/vendor/**', '**/.git/**'],
        },
    },
});
