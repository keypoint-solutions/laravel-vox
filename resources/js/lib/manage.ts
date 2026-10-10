import { routeUrl } from '@/lib/inertia';
import type { GroupItem, TranslationItem } from '@/types/manage';

export function translationActionUrl(route: string | undefined, translationId: number): string {
    return routeUrl(route, 'translation', translationId);
}

export function canDelete(translation: TranslationItem): boolean {
    return translation.deletion_unavailable_reason === null;
}

export function overrideLocales(translation: TranslationItem): string[] {
    return Object.keys(translation.published_overrides ?? {}).filter(
        (locale) => translation.published_overrides[locale] != null
    );
}

export function workflowStatusLabel(translation: TranslationItem): string {
    return translation.status === 'approved' ? 'Approved' : 'Pending review';
}

export function approvalActionLabel(translation: TranslationItem): string {
    return translation.status === 'approved' ? 'Return to review' : 'Approve translation';
}

export function frontendGroupTooltip(group: GroupItem): string {
    if (group.frontend_export_source === 'json') {
        return 'JSON translations are included in the frontend bundle';
    }

    if (group.frontend_export_source === 'configured') {
        return 'Included in the frontend bundle by configuration';
    }

    return 'Automatically included from frontend source usage';
}
