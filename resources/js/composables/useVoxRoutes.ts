import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

/**
 * The dashboard route URLs shared with every page.
 */
export function useVoxRoutes() {
    const page = usePage();

    return computed(() => page.props.vox?.routes);
}
