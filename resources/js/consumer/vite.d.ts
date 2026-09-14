import type { PluginOption } from 'vite';

export interface LaravelVoxViteOptions {
    runtime?: boolean;
    langPath?: string;
    additionalLangPaths?: string[];
    frontendGroups?: string[];
    manifestPath?: string;
}

export default function laravelVox(options?: LaravelVoxViteOptions): PluginOption[];
