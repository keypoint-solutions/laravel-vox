/** Serialize catalogue refreshes so an older fetch cannot overwrite a newer one. */
export function registerRuntimeHotReload(hot, refresh) {
    let pending = Promise.resolve();
    const report = ({ message }) => console.error(message);
    const updated = () => {
        pending = pending
            .then(refresh)
            .catch((error) => report({ message: `[vox] Unable to refresh translations: ${error.message}` }));
        return pending;
    };
    hot.on('vox:translations-updated', updated);
    hot.on('vox:translations-error', report);
    hot.dispose(() => {
        hot.off('vox:translations-updated', updated);
        hot.off('vox:translations-error', report);
    });
}
