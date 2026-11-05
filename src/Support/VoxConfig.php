<?php

namespace KeypointSolutions\LaravelVox\Support;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use KeypointSolutions\LaravelVox\Ai\OpenAiModelCatalog;

/**
 * Single place for Vox settings that are read throughout the package, together with their fallbacks.
 */
class VoxConfig
{
    public static function connectionName(): string
    {
        return (string) config('vox.database.connection', 'vox');
    }

    public static function connection(): Connection
    {
        return DB::connection(self::connectionName());
    }

    public static function langPath(): string
    {
        return rtrim((string) config('vox.paths.lang', lang_path()), DIRECTORY_SEPARATOR);
    }

    public static function missingPrefix(): string
    {
        return (string) config('vox.parse.missing_translation_prefix', '🚩');
    }

    /**
     * Whether wording published through Vox survives a release that re-installs the language files.
     * Unset, it is enabled everywhere except the `local` environment, where the files are the source of truth.
     */
    public static function retainsCustomWording(): bool
    {
        $configured = config('vox.publishing.retain_custom_wording');

        if ($configured === null || $configured === '') {
            return ! app()->environment('local');
        }

        return filter_var($configured, FILTER_VALIDATE_BOOL);
    }

    public static function translateModel(): string
    {
        return (string) config('vox.translate.model', OpenAiModelCatalog::DEFAULT_MODEL);
    }

    public static function translateDriver(): string
    {
        return (string) config('vox.translate.driver', 'openai');
    }
}
