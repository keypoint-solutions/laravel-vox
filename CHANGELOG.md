# Changelog

All notable changes to `laravel-vox` will be documented in this file.

## Unreleased

### Added

- Separate source usage from configured retention, with exact occurrences and possible dynamic matches.
- Confirmed orphan/unpublished dynamic key deletion, reversible Ignore in Vox, and backward-compatible `retained_keys` configuration.

- Live translation text updates in the Vox Vue integration, preserving mounted components and unsaved form state during translation HMR.
- Incremental Vite translation reloads with per-file PHP parsing caches and separate locale modules, avoiding recompilation of other languages and generated `php_*.json` files.

- Destructive translation-only and full-data resets in Settings and `vox:reset`, with typed confirmations, transactional deletion, audit recording, and published language file preservation.

- Initial Laravel translation management package with automatic service-provider discovery, configurable routes, and a gate-protected Inertia/Vue dashboard.
- Static translation scanning for PHP, Blade, JavaScript, TypeScript, and Vue, with configurable dynamic-key patterns.
- Translation import, local and remote synchronization, reconciliation, validation, and publishing to Laravel language files.
- AI-assisted translation, locale provisioning, settings management, and audit history.
- Dedicated SQLite storage by default, with support for existing configured database connections.
- `vox:setup` to prepare the database, run package migrations, and publish dashboard assets; `vox:install` remains a compatibility alias.
- Optional bundled or runtime-loaded Vue translations with asynchronous initialization, ordered locale preferences, reactive locale switching, and opt-in local/session storage persistence.
- Composer and npm frontend entry points, automatic Vite aliases, and TypeScript declarations.
- Testbench/Pest package tests, frontend consumer tests, and a Laravel consumer application with browser integration tests.

### Maintenance

- Accept literal string concatenation in translation files while rejecting executable expressions.
- Ignore translation-like calls inside PHP strings and comments, and avoid registering interpolated PHP strings as static keys.
- Separate connection registration from filesystem initialization so application boot does not create database files.
- Exclude the consumer application and development tooling from package archives.
- Separate npm consumer dependencies from dashboard build dependencies and declare Vue/Vite peer requirements.
- Restore automated package compatibility checks and frontend consumer tests.
- Remove the unused skeleton migration stub.
