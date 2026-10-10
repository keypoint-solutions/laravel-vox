import type { SelectOption } from '@/components/ui';

export interface GroupItem {
    name: string;
    total: number;
    is_frontend_exported: boolean;
    frontend_export_source: 'configured' | 'detected' | 'json' | null;
    is_json: boolean;
}

export interface OccurrenceItem {
    id: number;
    file_path: string;
    line_number: number | null;
    context_before: string | null;
    context_after: string | null;
}

export interface TranslationItem {
    fallback: Record<string, { mode: string; published_mode: string; selection: string }>;
    id: number;
    group: string | null;
    key: string;
    display_key: string;
    status: string;
    freshness_status: string | null;
    has_missing_values: boolean;
    is_frontend: boolean;
    is_orphan: boolean;
    is_dynamic: boolean;
    is_retained: boolean;
    is_pending_delete: boolean;
    is_placeholder_key: boolean;
    deletion_unavailable_reason: string | null;
    matching_patterns: string[];
    retention_sources: string[];
    dynamic_occurrences: { file: string; line: number | null; context: string | null }[];
    dynamic_pattern: string | null;
    source: string | null;
    updated_at: string | null;
    values_count: number;
    values: Record<string, string>;
    blank_locales: string[];
    draft_locales: string[];
    pending_publish_locales: string[];
    published_overrides: Record<string, string | null>;
    file_values: Record<string, string | null>;
    occurrences: OccurrenceItem[];
}

export interface TranslationsPayload {
    data: TranslationItem[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
}

export interface ManageFilters {
    group: string | null;
    search: string;
    status: string | null;
    sort: string;
    scope: string;
}

export interface FallbackRule {
    locale: string;
    scope: string;
    group: string | null;
    key: string | null;
    mode: string;
    published_mode: string;
}

export interface DynamicPattern {
    pattern: string;
    is_frontend: boolean;
    sources: string[];
}

export type ManagePageProps = {
    fallbackRules: FallbackRule[];
    groups: GroupItem[];
    translations: TranslationsPayload;
    locales: string[];
    missingTranslationPrefix: string;
    baseLocale: string;
    filters: ManageFilters;
    statusOptions: SelectOption[];
    sortOptions: SelectOption[];
    lastSyncAt: string | null;
    totalTranslations: number;
    dynamicPatterns: DynamicPattern[];
    ai: {
        available: boolean;
    };
};

export type CleanupAction = 'delete' | 'restore';

export type ToastTone = 'success' | 'error';
