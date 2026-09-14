import * as initialCatalogue from 'virtual:laravel-vox/translations';

import { createVoxController } from './shared.js';

export { trans, trans_choice, transChoice, wTrans, wTransChoice } from 'laravel-vue-i18n';

let catalogue = initialCatalogue;

const controller = createVoxController(async (locale) => {
    const [json, php] = await Promise.all([catalogue.loadVoxLocale(locale), catalogue.loadVoxLocale(`php_${locale}`)]);

    return { ...(php.default ?? php), ...(json.default ?? json) };
});
controller.configureLocales(catalogue.availableVoxLocales);

if (import.meta.hot) {
    import.meta.hot.accept('virtual:laravel-vox/translations', async (updatedCatalogue) => {
        if (!updatedCatalogue) return;
        catalogue = updatedCatalogue;
        controller.configureLocales(catalogue.availableVoxLocales);
        await controller.refreshMessages();
    });
}

/** @param {import('./vue.js').VoxI18nOptions} options */
export function createVoxI18n(options = {}) {
    return controller.plugin(controller.configure(options));
}

/** Prepare translations before mounting the application. */
export async function createVox(options = {}) {
    return controller.initialize(controller.configure(options));
}

export const availableVoxLocales = controller.availableLocales;
export const setVoxLocale = controller.setLocale;
export const useVox = controller.useVox;
export default createVoxI18n;
