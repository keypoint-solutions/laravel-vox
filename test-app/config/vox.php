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
        'groups' => [
            'mode' => env('VOX_FRONTEND_GROUPS_MODE', 'auto'),
            'values' => env('VOX_FRONTEND_GROUPS', ''),
        ],
        'manifest' => storage_path('vox/frontend.json'),
        'runtime' => [
            'enabled' => env('VOX_FRONTEND_RUNTIME_ENABLED', true),
            'path' => storage_path('vox/frontend-translations'),
            'middleware' => [],
        ],
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
    'dynamic_keys' => [
        'manifest' => storage_path('vox/dynamic.json'),
        'patterns' => [
            'auth.*',
            'pagination.*',
            'passwords.*',
            'validation.*',
            'frontend.dynamicLabels.values.*',
            'frontend.dynamicLabels2.values.*',
        ],
        'bindings' => [
            // 'enums.user_roles.*' => App\Enums\UserRole::class,
            // 'enums.order_statuses.*' => ['draft', 'submitted', 'paid'],
        ],
    ],
    'parse' => [
        'paths' => [
            '/app',
            '/resources/js',
            '/resources/views',
            '/vendor/laravel/framework/src',
            '/vendor/laravel/cashier/src',
            '/routes',
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
        'preserve_existing_format' => false,
        'keep_orphan_other_locales_keys' => env('VOX_PARSE_KEEP_ORPHAN_OTHER_LOCALES_KEYS', true),
        'obsolete' => env('VOX_PARSE_OBSOLETE', 'discard'),
        'sort_obsolete_last' => true,
        'escape_unicode' => false,
        'missing_translation_prefix' => env('VOX_MISSING_TRANSLATION_PREFIX', '🚩'),
    ],
    'translate' => [
        'driver' => env('VOX_TRANSLATE_DRIVER', 'openai'),
        'model' => env('VOX_TRANSLATE_MODEL', env('VOX_OPENAI_MODEL', 'gpt-5.4-mini')),
        'prompt' => env('VOX_TRANSLATE_PROMPT',
            'Translate the user message from :source to :target. Return only the translation. Preserve Laravel placeholders, tokens, whitespace, line breaks, and all HTML or Markdown markup exactly.'),
        'guidance' => env('VOX_TRANSLATE_GUIDANCE', ''),
        'use_context' => env('VOX_TRANSLATE_USE_CONTEXT', true),
        'terms' => [
            'do_not_translate' => [],
            'fixed' => [],
        ],
        'placeholder_prefixes' => [],
        'locales' => [
            'mode' => env('VOX_TRANSLATE_LOCALES_MODE', 'auto'),
            'values' => env('VOX_TRANSLATE_LOCALES', ''),
        ],
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
