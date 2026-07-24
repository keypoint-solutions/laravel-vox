import type { PluginOption } from 'vite';

export interface LaravelVoxViteOptions {
    langPath?: string;
    groups?: string[];
    manifestPath?: string;
}

export default function laravelVox(options?: LaravelVoxViteOptions): PluginOption[];
