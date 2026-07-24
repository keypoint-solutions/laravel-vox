<?php

use KeypointSolutions\LaravelVox\Http\Middleware\Authorize;
use KeypointSolutions\LaravelVox\Http\Middleware\HandleInertiaRequests;

return [
    'gui' => [
        'enabled' => env('VOX_GUI_ENABLED', true),
    ],
    'routes' => [
        'auto_register' => env('VOX_ROUTES_AUTO_REGISTER', true),
        'prefix' => env('VOX_ROUTES_PREFIX', 'vox'),
    ],
    'features' => [
        'dashboard' => true,
        'sync' => true,
        'manage' => true,
        'publish' => true,
        'audit' => true,
        'settings' => true,
    ],
    'frontend' => [
        'groups' => env('VOX_FRONTEND_GROUPS', 'auto'),
        'manifest' => storage_path('vox/frontend.json'),
    ],
    'system' => [
        'middleware' => [
            'web',
            Authorize::class,
            HandleInertiaRequests::class,
        ],
        'bypass_auth_in_local' => env('VOX_BYPASS_AUTH_IN_LOCAL', true),
    ],
    'database' => [
        'connection' => env('VOX_DB_CONNECTION', 'vox'),
        'path' => env('VOX_DB_PATH', storage_path('vox/vox.sqlite')),
    ],
    'paths' => [
        'lang' => lang_path(),
    ],
    'parse' => [
        'paths' => [
            '/app',
            '/resources/js',
            '/resources/views',
            '/vendor/laravel/framework/src',
            '/vendor/laravel/cashier/src',
            '/routes',
            '/src',
        ],
        'exclude' => [
            'dist/*',
            '*/dist/*',
            'dist\\*',
            '*\\dist\\*',
        ],
        'extensions' => ['php', 'blade.php', 'js', 'ts', 'vue'],
        'context_lines' => 3,
        'max_occurrences' => 5,
        'add_context_comments' => env('VOX_PARSE_ADD_CONTEXT_COMMENTS', false),
        'add_occurrence_comments' => env('VOX_PARSE_ADD_OCCURRENCE_COMMENTS', false),
        'output' => 'flat',
        'preserve_existing_format' => true,
        'keep_orphan_other_locales_keys' => env('VOX_PARSE_KEEP_ORPHAN_OTHER_LOCALES_KEYS', true),
        'obsolete' => env('VOX_PARSE_OBSOLETE', 'discard'),
        'sort_obsolete_last' => true,
        'escape_unicode' => false,
        'missing_translation_prefix' => env('VOX_MISSING_TRANSLATION_PREFIX', '🚩'),
        'protected_keys' => [
            'auth.',
            'pagination.',
            'passwords.',
            'validation.',
        ],
    ],
    'translate' => [
        'driver' => env('VOX_TRANSLATE_DRIVER', 'openai'),
        'model' => env('VOX_TRANSLATE_MODEL', env('VOX_OPENAI_MODEL', 'gpt-5.4-mini')),
        'prompt' => env('VOX_TRANSLATE_PROMPT',
            'You are a professional translator for a Laravel application. Translate the following string from :source to :target. Keep placeholders, HTML or markdown tags and new lines intact. Output only the translated string.'),
        'guidance' => env('VOX_TRANSLATE_GUIDANCE', ''),
        'use_context' => env('VOX_TRANSLATE_USE_CONTEXT', true),
        'terms' => [
            'do_not_translate' => [],
            'fixed' => [],
        ],
        'placeholder_prefixes' => [],
        'locales' => env('VOX_TRANSLATE_LOCALES', 'auto'),
        'base_locale' => env('VOX_TRANSLATE_BASE_LOCALE', 'auto'),
        'providers' => [
            'openai' => [
                'api_key' => env('OPENAI_API_KEY'),
            ],
        ],
    ],
    'sync' => [
        'enabled' => env('VOX_SYNC_ENABLED', true),
        'key' => env('VOX_SYNC_KEY'),
        'middleware' => [],
    ],
];
