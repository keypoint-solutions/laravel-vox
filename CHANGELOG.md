# Changelog

Notable changes to Laravel Vox are documented here. See the [README](README.md) for installation, configuration, and command examples.

## Unreleased

## 1.0.3 — 2026-09-15

- Batch synchronization and publishing queries to avoid oversized SQLite parameter lists and reduce model memory usage. File updates remain accumulated across batches before writing.

## 1.0.2 — 2026-09-15

- Allow `vox:compile` to build frontend translations before the Vox database exists, using application locale configuration and language files.

## 1.0.1 — 2026-09-15

- Enable frontend runtime translation delivery by default in PHP and the Vite plugin. Set `VOX_FRONTEND_RUNTIME_ENABLED=false` to retain bundled delivery.
- Existing published configuration is not overwritten: align its runtime setting with Vite, then restart Vite or rebuild when changing delivery modes.

## 1.0.0 — 2026-09-15

Initial public release for Laravel 12 and 13.

### Translation discovery and storage

- Scan PHP, Blade, JavaScript, TypeScript, and Vue for translation usage, including supported dynamic expressions and plural helpers.
- Bind dynamic key patterns to enums, explicit lists, provider classes, or callbacks; protect key families through retention rules.
- Support PHP and JSON translations, flat or nested PHP arrays, nested group folders, and vendor namespaces across parsing, synchronization, and publication.
- Keep application translations file-based, with a separate SQLite database by default for drafts, approvals, and reconciliation. Existing Laravel database connections are also supported.

### Management and review

- Provide a responsive, gate-protected Inertia/Vue manager with light/dark themes, search, group pills, status-aware counts, and separate Missing and Empty filters.
- Edit and approve values per locale, translate missing wording with AI, or explicitly retranslate existing wording. Protect Laravel placeholders and skip unusable source values.
- Add languages and concrete dynamic keys, automatically supplying the selected pattern's fixed prefix.
- Pull published or draft wording from remote environments and review differences alongside current values and the configured default-locale reference.
- Edit either comparison, confirm the final selection, or ask AI to suggest a choice. Support page-level bulk AI choices and confirmation, plus acceptance or retention of all matching values.
- Preserve resolved decisions across unchanged syncs and reject stale review submissions.
- Offer translation ZIP import/export, structured audit history, and translation-only or full-data resets that preserve published files.

### Publication and deployment

- Publish approved locale values independently while preserving unapproved drafts; support restoring application wording without discarding a draft.
- Schedule deletion of any key, cancel before publication, and remove translation files left empty.
- Use consistent flat/nested formatting, preserve existing file permissions, roll back failed file operations, and reject invalid JSON encoding without overwriting files.
- Reconcile fresh deployment defaults with published overrides and unpublished drafts through `vox:deploy`.
- Emit `TranslationsPublished` after successful publication for application-owned frontend build hooks.
- Keep Vox migration history separate from application migrations through `vox:setup`.

### Frontend and developer tools

- Deliver translations as bundled assets or generated runtime catalogues, with file-backed HTTP endpoints and cache revalidation.
- Provide asynchronous Vue initialization, locale discovery and fallback, reactive switching, pluralization, and optional locale persistence.
- Automatically discover frontend groups and hot-reload translation changes through Vite, including PHP-served runtime translations, while preserving mounted component state.
- Expose Composer and npm frontend entry points, TypeScript declarations, and Artisan commands for setup, parsing, translation, synchronization, review, publication, compilation, and maintenance.
- Include PHP compatibility checks, JavaScript consumer tests, and a browser-tested demo application. Exclude development tooling and the demo application from Composer archives.

### Notes for early development installations

- Run `php artisan vox:setup --force` after upgrading to apply Vox migrations and refresh manager assets. `vox:install` remains a compatibility alias.
- The former Ignore feature is replaced by pending deletion. Previously ignored keys migrate to pending deletion; review them before publishing.
- Laravel 11 is no longer supported. Inertia Laravel requires `^2.0.20` or `^3.0` and is installed automatically through Composer.
