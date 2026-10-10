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

    public static function translateModel(): string
    {
        return (string) config('vox.translate.model', OpenAiModelCatalog::DEFAULT_MODEL);
    }

    public static function translateDriver(): string
    {
        return (string) config('vox.translate.driver', 'openai');
    }
}
