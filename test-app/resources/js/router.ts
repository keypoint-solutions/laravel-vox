import { createRouter, createWebHistory } from 'vue-router';
import TestOne from '@/pages/TestOne.vue';
import TestTwo from '@/pages/TestTwo.vue';
import Welcome from '@/pages/Welcome.vue';

const supportedLocales = (window as Window & { voxLocales?: string[] }).voxLocales ?? ['en'];
const pathLocale = window.location.pathname.split('/')[1] ?? '';
const base = supportedLocales.includes(pathLocale) ? `/${pathLocale}` : '/';

const router = createRouter({
    history: createWebHistory(base),
    routes: [
        {
            path: '/',
            name: 'welcome',
            component: Welcome,
        },
        {
            path: '/test-one',
            name: 'test-one',
            component: TestOne,
        },
        {
            path: '/test-two',
            name: 'test-two',
            component: TestTwo,
        },
    ],
});

export default router;
