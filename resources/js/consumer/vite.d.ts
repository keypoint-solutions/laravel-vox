import type { PluginOption } from 'vite';

export interface LaravelVoxViteOptions {
    /** Defaults to VOX_FRONTEND_RUNTIME_ENABLED from Vite's environment, or true when unset. Explicit values override it. */
    runtime?: boolean;
    /** Refresh frontend groups before builds and after source edits when Artisan exists at Vite's root. Defaults to true. */
    frontendDiscovery?: boolean;
    /** Automatically compile runtime catalogues and refresh browsers during Vite development. Defaults to true. */
    runtimeHotReload?: boolean;
    /** PHP executable used for Artisan discovery and compilation. Defaults to php on PATH. */
    phpBinary?: string;
    langPath?: string;
    additionalLangPaths?: string[];
    frontendGroups?: string[];
    manifestPath?: string;
}

export default function laravelVox(options?: LaravelVoxViteOptions): PluginOption[];
