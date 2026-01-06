import globals from 'globals';
import pluginJs from '@eslint/js';
import tseslint from 'typescript-eslint';
import pluginVue from 'eslint-plugin-vue';
import eslintConfigPrettier from 'eslint-config-prettier';
import simpleImportSort from 'eslint-plugin-simple-import-sort';
import unusedImports from 'eslint-plugin-unused-imports';

/** @type {import('eslint').Linter.Config[]} */
export default [
    // 1. Setup Global Ignores (Folders to exclude)
    {
        ignores: [
            'bootstrap/ssr/**',
            'build/**',
            'dist/**',
            'node_modules/**',
            'public/**',
            'storage/**',
            'test-app/**',
            'tests/**',
            'vendor/**',
        ],
    },

    // 2. Base Configurations
    pluginJs.configs.recommended,
    ...tseslint.configs.recommended,
    ...pluginVue.configs['flat/recommended'],

    // 3. The "Workhorse" Config: Parsers & Plugins
    {
        files: ['**/*.{js,ts,vue}'],
        plugins: {
            'simple-import-sort': simpleImportSort,
            'unused-imports': unusedImports,
        },
        languageOptions: {
            globals: {
                ...globals.browser,
                ...globals.es2021,
                route: 'readonly', // Ziggy
            },
            // This allows JS/TS/Vue files to all use the TS parser
            parser: pluginVue.parser,
            parserOptions: {
                parser: tseslint.parser,
                sourceType: 'module',
                extraFileExtensions: ['.vue'],
            },
        },
        rules: {
            // --- Import Sorting ---
            'simple-import-sort/imports': 'error',
            'simple-import-sort/exports': 'error',

            // --- Unused Import Cleanup ---
            'no-unused-vars': 'off',
            '@typescript-eslint/no-unused-vars': 'off',
            'unused-imports/no-unused-imports': 'error',
            'unused-imports/no-unused-vars': [
                'warn',
                { vars: 'all', varsIgnorePattern: '^_', args: 'after-used', argsIgnorePattern: '^_' },
            ],

            // --- Vue / General ---
            'vue/multi-word-component-names': 'off',
            '@typescript-eslint/no-explicit-any': 'warn',
        },
    },

    // 4. Prettier (Must always be last to override formatting)
    eslintConfigPrettier,
];
