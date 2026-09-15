# Laravel Vox

Laravel Vox brings source discovery, AI translation, remote review, and controlled publishing to Laravel, with a dedicated management UI and optional Vue integration.

**Your Laravel translation files remain the source of truth for the running application.** Vox uses a separate database for drafts, approvals, and review history. Publish writes approved wording back to files; normal translation lookups never query the Vox database.

Vox defaults to a **standalone SQLite database** at `storage/vox/vox.sqlite` and keeps its generated data in the consuming application's `storage/vox` directory. You can select another configured Laravel database connection instead.

## Features

### Laravel files, from discovery to publication

- **Flat or nested translations.** Work with PHP arrays, dotted keys, JSON translations, vendor namespaces, and groups in nested folders. Parse and Publish share the same format rules, with an option to preserve existing PHP layout.
- **Source-aware parsing.** Discover translation calls in PHP, Blade, JavaScript, TypeScript, and Vue; record where keys are used; and update language files. Command output ends with a summary, with detailed occurrences available when needed.
- **Dynamic-key detection and protection.** Detect supported expressions that assemble translation keys at runtime. Review their patterns and retain whole key families, such as `roles.*`, when static scanning cannot prove their usage.
- **Enum and custom dynamic bindings.** Bind a key pattern such as `enums.roles.*` to a PHP enum, an explicit list, a provider class, or a callback returning an iterable. Vox expands the binding into concrete keys; backed enums supply their values and unit enums their case names. See [Dynamic keys and retention](#dynamic-keys-and-retention) for examples.
- **Deliberate cleanup.** Identify orphaned keys, schedule any key for deletion, or cancel before publishing. Parse's obsolete-key policy controls source cleanup; publishing also removes files left empty.
- **Safe file updates.** Preserve existing file permissions, support readable Unicode, and roll back failed file operations. Unapproved drafts stay unpublished, and approval is tracked independently for each locale.

### A focused translation management UI

- **Find the work that matters.** Search keys and wording, filter by group and status, and use status-aware group counts. Missing translations and keys with empty default wording have separate views.
- **Edit and translate efficiently.** Edit individual values, approve in bulk, AI-translate only missing values, or deliberately retranslate existing wording. AI translation protects Laravel placeholders and skips unusable source wording.
- **Review differences side by side.** Edit either the current or incoming value, select the version to keep, and confirm the final wording. Expand the default-language reference without leaving the comparison.
- **Let AI help choose.** Ask AI to compare the alternatives against the configured default locale, then adjust its suggestion before confirming. Bulk AI choices and confirmation work on checked rows on the current page.
- **Keep control of publication.** Accept changes now and publish when ready. Inspect audit activity, add languages, manage remote environments, and review pending deletions from the same interface.
- **Comfortable on smaller screens.** Responsive layouts, compact group pills, wrapping controls, and light/dark themes keep everyday management usable across screen sizes.

### Bring translations back from remote environments

Pull published translations from production or staging to recover the latest wording edited by key users. Review incoming changes and conflicts before accepting them; pulling alone does not replace local wording. Drafts can be pulled explicitly.

Repeated unchanged syncs preserve resolved decisions. Stale review submissions are rejected rather than silently overwriting newer work. ZIP import/export is also available for transferring language files.

### Choose how the frontend receives translations

| Delivery    | When translations change                                                                                                    |
| ----------- | --------------------------------------------------------------------------------------------------------------------------- |
| **Bundled** | Include discovered frontend groups in your JavaScript build; rebuild assets to distribute new wording.                      |
| **Runtime** | Fetch generated catalogues from the server; refresh them through Publish or Compile **without rebuilding frontend assets**. |

The Vue integration supports locale discovery, fallback, pluralization, and reactive translations. Frontend group discovery helps limit the PHP groups exposed to the browser. Runtime endpoints serve generated files with cache revalidation, without reading the Vox database.

For custom build pipelines, the [`TranslationsPublished` event](#publication-events) lets your application queue its own asset rebuild after successful publication. Deployment reconciliation preserves published management overrides and unpublished drafts when fresh application files arrive.

### Built for developer workflows

- **A complete CLI alongside the UI.** Discover, sync, translate, review, publish, and compile translations through Artisan. Use explicit environment IDs and non-interactive options in scripts, or integrate `vox:deploy` into your release process. See the [command reference](#commands) for examples.
- **Hot reload, including PHP-served translations.** With the Vox Vite plugin running, edits to PHP or JSON language files update reactive frontend translations without a manual page refresh. In runtime mode, the plugin runs `vox:compile`, notifies the browser, and reloads its dictionaries—even though PHP serves the translation catalogues instead of bundling them into JavaScript.
- **Automatic frontend discovery during development.** Supported translation calls update the frontend group manifest as source files change, keeping the browser's translation catalogue aligned with the code.
- **Fits existing applications.** Keep Laravel's translation helpers and language files, install the prebuilt manager without adding an application Inertia setup, and use the publication event for custom asset pipelines.

**Start here:** [Installation](#installation) · [Configuration](#configuration) · [Backend Use](#backend-use) · [Frontend Use](#frontend-use) · [Translation Management](#translation-management) · [Developer Workflow](#typical-developer-workflow)

## Requirements

- PHP 8.2+ (8.x) and Laravel 12 or 13.
- PDO SQLite for the default Vox database, or an application-configured database connection.
- PHP Zip extension for translation archives and legacy ZIP synchronization.
- Optional Vue integration: Vue 3.5+, Vite 8 for bundling/development, and a Node.js version supported by Vite.
- Optional AI translation: an OpenAI API key, or a custom translation driver.

## Installation

```bash
composer require keypoint-solutions/laravel-vox
php artisan vendor:publish --tag=vox-config
php artisan vox:setup
```

Composer automatically installs Inertia Laravel and the other PHP dependencies. Your application does not need its own Inertia setup.

Setup creates `storage/vox/vox.sqlite` when needed, runs package migrations, and publishes the prebuilt manager assets. Open `/vox` in your application. The manager itself needs no application Vite integration.

After upgrading, run `php artisan vox:setup --force` to apply migrations and refresh assets. `vox:install` remains an alias for setup.

### Authorization

Define gates in an application service provider. Replace `is_admin` with your application's authorization rule:

```php
use App\Models\User;
use Illuminate\Support\Facades\Gate;

Gate::define('viewVox', fn (User $user): bool => $user->is_admin);
Gate::define('manageVoxSettings', fn (User $user): bool => $user->is_admin);
```

Settings and reset additionally require `manageVoxSettings`. Local environments bypass gates by default; set `VOX_BYPASS_AUTH_IN_LOCAL=false` to enforce them locally. Keep production's `APP_ENV` set correctly.

## Configuration

The source of truth for all options is [config/vox.php](config/vox.php). Settings in the manager can override supported defaults; provider credentials belong in the environment.

A small explicit configuration:

```dotenv
VOX_TRANSLATE_LOCALES_MODE=configured
VOX_TRANSLATE_LOCALES=en,fr,de
VOX_TRANSLATE_BASE_LOCALE=en

VOX_TRANSLATE_DRIVER=openai
OPENAI_API_KEY=your-api-key
```

To use another database, define a connection in `config/database.php` and set `VOX_DB_CONNECTION` to its name. To relocate the default SQLite file, set `VOX_DB_PATH`. Changing connections does not transfer existing Vox data.

The base locale defaults to `app.locale`; it does not have to be English. Locale and frontend-group lists use `mode: auto|configured` and `values`. In PHP config, `values` may be an array; environment values are comma-separated. `configured` replaces automatic discovery.

| Setting                                      | Purpose                                                    |
| -------------------------------------------- | ---------------------------------------------------------- |
| `vox.paths.lang`                             | Language directory; defaults to Laravel's `lang_path()`    |
| `vox.database.connection` / `path`           | Dedicated connection and default SQLite path               |
| `vox.parse.paths` / `exclude`                | Source locations to scan                                   |
| `vox.parse.obsolete`                         | `discard` (default), `keep`, or `comment` obsolete keys    |
| `vox.parse.output`                           | `flat` or `nested` PHP arrays                              |
| `vox.parse.preserve_existing_format`         | Preserve existing key layouts; disable to enforce `output` |
| `vox.parse.missing_translation_prefix`       | Missing-value marker; defaults to `🚩`                     |
| `vox.translate.model` / `guidance` / `terms` | AI model, instructions, and terminology                    |
| `vox.frontend.groups`                        | Automatic or configured PHP groups exposed to the frontend |
| `vox.frontend.runtime.enabled`               | Serve generated JSON rather than bundle translations       |

Parse, Translate, Publish, and application-wording restoration share PHP formatting rules. JSON catalogues use flat keys. Output has no BOM; JSON encoding errors throw rather than silently replacing translations with an empty object.

### Dynamic keys and retention

Literal calls are easiest to discover. For keys assembled at runtime, retain the family explicitly:

```php
// config/vox.php — append to the existing retained_keys list.
'retained_keys' => [
    'auth.*', 'pagination.*', 'passwords.*', 'validation.*',
    'fitbit_sync_warnings.*',
],
```

A retention rule protects existing values; it cannot recover deleted wording. Supported template strings and concatenations are also detected automatically. Arbitrary variables, custom translation wrappers, and dynamic array lookups may need explicit rules. Existence checks such as `Lang::has()` are not translation usages.

When suffixes are known, enumerate them with a binding:

```php
'dynamic_keys' => [
    'bindings' => [
        'enums.roles.*' => App\Enums\UserRole::class,
        'features.*' => ['search', 'export'],
    ],
],
```

Backed enums use their values; unit enums use case names. A provider class may implement `KeypointSolutions\LaravelVox\DynamicKeyProvider::values(): iterable`. Runtime callbacks can be registered in a service provider:

```php
use KeypointSolutions\LaravelVox\Facades\LaravelVox;

LaravelVox::dynamicKeys('features.*', fn (): array => array_keys(config('features', [])));
```

Keep callbacks deterministic and side-effect free. **Retention does not expose a PHP group to the frontend**; configure frontend groups separately if usage cannot be discovered.

## Backend Use

Use Laravel's standard translation helpers:

```php
// lang/en/messages.php
return [
    'welcome' => 'Welcome, :name',
    'items' => '{0} No items|{1} One item|[2,*] :count items',
];
```

```php
__('messages.welcome', ['name' => 'Ana']);
trans_choice('messages.items', 2);
```

The same helpers work in Blade. JSON translations use the phrase directly, for example `__('Welcome')` with an entry in `lang/en.json`.

Nested folders retain Laravel's slash syntax: `lang/en/admin/messages.php` is addressed as `__('admin/messages.welcome')`. Namespaced groups use `__('package::admin/messages.welcome')`. Nested array keys use dots after the group name.

Run `php artisan vox:parse` to discover supported Laravel, Blade, JavaScript, TypeScript, and Vue calls and update language files. Static array lookups such as `Lang::array('messages.options')` retain that subtree. Configure retention before parsing runtime-only keys: the default obsolete policy removes undiscovered keys and files left empty.

## Frontend Use

Vox integrates with `laravel-vue-i18n`. Choose Composer sources or the npm package; use one integration consistently.

### Composer integration

```bash
npm install laravel-vue-i18n
```

Add Vox alongside your existing Laravel and Vue Vite plugins:

```js
// vite.config.js
import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';
import vox from './vendor/keypoint-solutions/laravel-vox/resources/js/consumer/vite.js';

export default defineConfig({
    plugins: [laravel({ input: ['resources/js/app.js'] }), vue(), vox()],
});
```

Initialize translations before mounting Vue (or inside your Inertia setup callback):

```js
import { createApp } from 'vue';
import { createVox } from '@laravel-vox/vue.js';
import App from './App.vue';

const translations = await createVox({ locale: 'fr', fallbackLocale: 'en' });
createApp(App).use(translations).mount('#app');
```

The plugin supplies the alias; TypeScript editors may also need a matching `paths` entry. Set the locale and fallback to your application's languages. Omitting `locale` uses the page language first; browser preferences are opt-in with `locale: navigator.languages`.

```vue
<script setup>
    import { computed } from 'vue';
    import { trans, useVox } from '@laravel-vox/vue.js';

    const { setLocale } = useVox();
    const heading = computed(() => trans('messages.welcome', { name: 'Ana' }));
</script>

<template>
    <h1>{{ heading }}</h1>
    <p>{{ $tChoice('messages.items', 2) }}</p>
    <button @click="setLocale('fr')">Français</button>
</template>
```

`useVox()` also exposes reactive `locale` and `locales`. Use `persist: 'local'` or `'session'` in `createVox()` to remember the selection. Import `transChoice` for pluralization in scripts. Always import initialization and helpers from the same entry point.

### npm alternative

```bash
npm install @keypoint-solutions/laravel-vox
```

Use `@keypoint-solutions/laravel-vox/vite` for the plugin and `@keypoint-solutions/laravel-vox/vue` for bundled initialization/helpers. For runtime delivery, use `@keypoint-solutions/laravel-vox/vue/runtime` instead.

### Delivery and exposed groups

| Mode    | Configuration                       | After publishing                                                                          |
| ------- | ----------------------------------- | ----------------------------------------------------------------------------------------- |
| Bundled | Default                             | Rebuild and deploy the application frontend                                               |
| Runtime | `VOX_FRONTEND_RUNTIME_ENABLED=true` | Generated JSON is served without a frontend rebuild; reload already-open production pages |

With Composer integration, `vox()` selects the matching `@laravel-vox/vue.js` implementation from that environment setting. Restart Vite or rebuild when changing modes; keep build and server settings aligned. Explicit `vox({ runtime: true })` affects Vite only.

PHP groups are selected automatically from frontend calls. Selection includes the **whole group**, not individual keys. JSON translations are included. To expose runtime-only PHP groups, configure the complete list:

```dotenv
VOX_FRONTEND_GROUPS_MODE=configured
VOX_FRONTEND_GROUPS=messages,fitbit_sync_warnings
```

Use `*` to include every PHP group, including nested folders and namespaces. `frontendGroups` on the Vite plugin overrides bundled selection only.

Vite runs `vox:frontend-discover` when an `artisan` file is available at its root. It refreshes group discovery on source edits and before builds. PHP edits reparse only the affected file and hot-reload its locale without remounting Vue. Runtime development recompiles catalogues with `vox:compile`. Set `frontendDiscovery: false` on the plugin if another process manages discovery.

Runtime files live in `storage/vox/frontend-translations`. Their HTTP endpoints read generated files, not the Vox database, and support cache revalidation. Run `php artisan vox:compile` to prepare them from current language files. For custom mounts, runtime initialization accepts `baseUrl: '/admin/translations'`; cross-origin authentication and CORS belong to the application.

## Translation Management

Start with:

```bash
php artisan vox:sync --parse
```

Then use `/vox/manage` and `/vox/sync` to review, and `/vox/publish` to publish when ready.

- **First import:** existing file values become approved application defaults.
- **Manual/AI edits:** saved as drafts until approved. Approval is per locale.
- **AI translate missing:** targets absent, empty, or marker-prefixed values; skips unusable base wording. **AI retranslate** replaces selected target wording.
- **Empty filter:** separates keys with empty/missing default wording from actionable missing translations.
- **Publish:** writes approved usable changes, refreshes previously published wording, and applies pending deletions. Other locales and unapproved drafts do not block publication.
- **Use application wording:** removes one locale's published override while preserving its draft.

Static usage, dynamic matches, retention, and orphan status describe source usage separately from approval. A wildcard match does not prove a key is actually used.

### Review incoming changes

**Sync local files** compares files with the database. **Parse and sync** updates files first. You may edit either comparison box, select it, and click **Confirm selection**; only the selected final wording is saved. Accepted or edited wording is approved, then published separately.

**Choose with AI** suggests a side using the default-locale reference, translation completeness, and objective correctness. You can change that choice before confirming. Bulk AI and **Confirm selections** operate on checked rows on the current page; confirmation uses your latest selections and edits. Bulk Accept/Keep can also use all matching rows across pages.

| Review state         | Meaning                                                              |
| -------------------- | -------------------------------------------------------------------- |
| Incoming             | Source wording changed since the acknowledged baseline               |
| Conflict             | Both sides changed, or an initial comparison found different wording |
| Local changes        | Local wording changed while the source stayed unchanged              |
| Resolved: kept local | Acknowledged differing values; no further confirmation needed        |
| Matching             | Current values agree                                                 |
| No longer in source  | Source omitted the value; no automatic local deletion                |

Repeated unchanged syncs do not reopen decisions. Changes since loading a review invalidate its selection token; refresh before retrying. Local-file comparisons refresh when files match published overrides.

### Add or delete keys and languages

Manage can create concrete keys covered by dynamic/retention patterns. The default-locale wording is required.

Delete any key from Manage, then Publish to remove all its locale values. Cancel pending deletion before publishing to keep it. Empty files are deleted, and failed file operations roll back. Rediscovery can recreate the key, **but cannot recover its deleted wording**. Deletion does not remove retention rules. The migration from older versions converts ignored keys to pending deletion without publishing them.

In Sync, **Application languages** creates a locale from the configured base locale, including nested PHP groups and vendor JSON. Without AI, nonempty source wording receives the missing marker. Optional AI skips unusable sources. These generated files become live application defaults immediately; this is different from Manage's AI draft workflow.

## Typical Developer Workflow

After [installation and configuration](#installation), the usual loop is:

```mermaid
flowchart LR
    A[Edit application code] --> B[vox:sync --parse]
    B --> C[Translate and review in Vox]
    C --> D[vox:publish]
    D --> E[Check and commit language files]
```

Run these commands from the consuming application:

| When                                       | What to run or do                                                                                                                                                                                   |
| ------------------------------------------ | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Starting frontend development              | `npm run dev` with the Vox Vite plugin configured. It handles frontend discovery and translation hot reload.                                                                                        |
| After adding or removing translation calls | `php artisan vox:sync --parse` to update language files and refresh the manager. Configure retention for runtime-only key families first.                                                           |
| Filling missing translations               | Use **AI translate missing** or edit values in Manage, then approve the wording you want to publish.                                                                                                |
| Ready to make approved wording live        | `php artisan vox:publish`. Runtime catalogues are refreshed automatically; bundled delivery needs `npm run build` for the distributable assets.                                                     |
| Before committing                          | Review the language-file diff, including deletions, then commit the intended files.                                                                                                                 |
| Deploying fresh release files              | `php artisan vox:deploy --no-interaction`, before activating the release. For bundled delivery, build frontend assets afterward. See [Deployment](#deployment) for ordering and retry requirements. |

### Variations

- **Prefer translating through the CLI?** Run `php artisan vox:translate` after parsing, inspect its changes, then run `php artisan vox:sync` to bring the file wording into the manager. CLI translation writes directly to files; it does not create drafts awaiting publication.
- **Edited language files directly?** Run `php artisan vox:sync` to review them in the manager. Outside Vite development, run `php artisan vox:compile` to refresh runtime catalogues, or rebuild bundled assets.
- **Need the latest production wording?** Run `php artisan vox:sync-remote --environment=1`, replacing `1` with the configured environment ID. Review incoming changes in Sync, accept the desired wording, then publish. Pulling alone does not replace local files.
- **Only need file discovery, without the manager?** Run `php artisan vox:parse` instead of `vox:sync --parse`.

You normally do not run `vox:frontend-discover` manually, or run `vox:compile` immediately after Publish: those steps are already handled by the Vite plugin and publication workflow respectively.

## Commands

Run commands from the consuming Laravel application. Use `php artisan help vox:COMMAND` for the complete option list.

### Setup and configuration

| Command                    | Description and usage                                                                                                                         |
| -------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------- |
| `php artisan vox:setup`    | Create the default database when needed, run Vox migrations, and publish manager assets. Add `--force` for production. Rerun after upgrading. |
| `php artisan vox:install`  | Compatibility alias for `vox:setup`; prefer `vox:setup` in new scripts.                                                                       |
| `php artisan vox:settings` | Display key configuration values, including the database, parsing rules, locales, and AI model.                                               |

Vox migrations are managed by `vox:setup` (also called by `vox:deploy`), separately from the application's normal `migrate` command.

### Discover, sync, and translate

| Command                     | Description and usage                                                                                                                                                                                                           |
| --------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `php artisan vox:parse`     | Scan source usage and update language files according to the configured format, retention, and obsolete-key rules. Does not import translations into the database. Add `-v` for detailed occurrences.                           |
| `php artisan vox:sync`      | Compare current language files with Vox's database and refresh usage metadata. Existing edits go through reconciliation. Add `--parse` to update language files first.                                                          |
| `php artisan vox:translate` | Translate missing values using the configured AI driver, writing **directly to language files**. Restrict work with `--path` or `--key`; use `--force` to retranslate existing wording. Unusable base wording is still skipped. |

```bash
# Discover keys, update files, and bring the results into the manager.
php artisan vox:sync --parse

# Translate one file (path relative to the language directory).
php artisan vox:translate --path=en/admin/messages.php

# Retranslate one key across the applicable target locales.
php artisan vox:translate --key=messages.welcome --force
```

Configure retained key families before parsing: the default obsolete policy discards undiscovered keys and removes files left empty. CLI translation and Manage share eligibility rules, but Manage saves drafts while the CLI changes live files.

### Remote pulls and review

| Command                             | Description and usage                                                                                                                                                                                                                                                         |
| ----------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `php artisan vox:generate-sync-key` | Generate and save the source application's sync key to `.env`. Add `--force` to replace an existing key, then update environments that use it.                                                                                                                                |
| `php artisan vox:sync-remote`       | Select a configured environment interactively and pull wording for review. Use `--environment=1` for a specific source, `--include-drafts` to fetch editable wording, and `--check` to fail when review or publication is still needed. Does not change local language files. |
| `php artisan vox:review`            | Inspect previously imported local-file changes. Select a remote source with `--environment=1`, or every source with `--environment=all`; the default is `files`. Displays the first page; use the UI for individual decisions.                                                |

```bash
# Pull a configured source without prompting, and check for outstanding work.
php artisan vox:sync-remote --environment=1 --check --no-interaction
php artisan vox:review --environment=1

# After inspection, choose ONE batch action for the source.
php artisan vox:review --environment=1 --accept-all
# Alternatively: php artisan vox:review --environment=1 --keep-all

# Publish approved wording separately when ready.
php artisan vox:publish
```

Review's batch actions apply to all matching actionable values, beyond the displayed page. `--accept-all` approves incoming wording; `--keep-all` acknowledges the current wording. They cannot be combined. Adding `--publish` to `--accept-all` publishes the accepted selection immediately.

### Publish, compile, and deploy

| Command                             | Description and usage                                                                                                                                                                                                                              |
| ----------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `php artisan vox:publish`           | Write approved changes, refresh previously published wording, and apply pending deletions. Also refresh frontend catalogues. Add `--published-only` to regenerate recorded defaults and overrides without applying drafts or pending deletions.    |
| `php artisan vox:frontend-discover` | **Advanced; normally automatic.** The Vox Vite plugin runs this at startup, on relevant source changes during development, and before production builds. Refreshes the frontend group manifest without changing language files or database values. |
| `php artisan vox:compile`           | Generate frontend translation JSON from current language files. Does not import database values, publish drafts, or run a JavaScript build.                                                                                                        |
| `php artisan vox:deploy`            | Run setup, import freshly installed release defaults, and restore published management overrides while preserving drafts. Use only after installing fresh release language files; see [Deployment](#deployment).                                   |

```bash
# Refresh runtime catalogues after editing language files directly.
php artisan vox:compile

# Restore recorded published wording without importing new defaults.
php artisan vox:publish --published-only

# Reconcile translations during deployment, before activating the release.
php artisan vox:deploy --no-interaction
```

Run `vox:frontend-discover` manually only for a custom build pipeline or when automatic discovery is disabled with `frontendDiscovery: false`.

Runtime delivery needs no frontend rebuild after refreshing catalogues. Bundled delivery requires your normal frontend build afterward.

### Maintenance and reset

| Command                             | Description and usage                                                                                                        |
| ----------------------------------- | ---------------------------------------------------------------------------------------------------------------------------- |
| `php artisan vox:cleanup`           | Delete audit records older than 90 days. This does **not** delete translation keys or clean language files.                  |
| `php artisan vox:reset`             | Permanently clear database translation work while keeping settings, environments, and audits. Published files remain intact. |
| `php artisan vox:reset --scope=all` | Also delete settings, environments, and audit history. Published files remain intact.                                        |

Reset asks for typed confirmation. `--force` skips it for intentional automation; back up Vox's database first. Sync can recover file values afterward, but cannot recover unpublished drafts.

## Remote Sync and Archives

On the source application, run `php artisan vox:generate-sync-key`. Add its URL and key under Sync → **Remote environments**, then pull published values for review. Pulling drafts is explicit. Current endpoints exchange published snapshots; legacy ZIP sources are also supported.

```bash
php artisan vox:sync-remote --environment=1 --check --no-interaction
php artisan vox:review --environment=1
```

`--check` fails for pull errors, unresolved changes, or selected-source wording not yet reflected in local files. It does not push or deploy. Each environment has an independent review baseline. Set `VOX_SYNC_ENABLED=false` on installations that do not expose a sync source.

Sync can download the file result Publish would produce, without modifying local files. ZIP import validates and copies files, then leaves database reconciliation to a subsequent local sync. Archives reject traversal, symlinks, and executable PHP; PHP translation files must return literal arrays of strings.

## Deployment

Use a tagged Composer release in consuming applications rather than a development path symlink. Keep the target's `storage/vox` persistent and backed up across releases; never replace it with build-machine data. Language files and generated runtime files must be writable for management publication.

After installing **fresh release language files**, before activating the release:

```bash
php artisan vox:deploy --no-interaction
```

This includes setup/migrations and manager assets, imports shipped defaults, and reapplies published overrides while preserving drafts. It does not pull remote environments. For bundled delivery, build frontend assets after preparing effective translations. Runtime delivery reads the generated catalogues.

A deployment retry must reinstall fresh source language files before rerunning `vox:deploy`. To regenerate without importing, use `vox:publish --published-only` instead. Vox does not retain original release snapshots or activate/roll back application releases.

All publishing processes must share the configured mutation lock. If management remains writable during release activation, coordinate activation under `VoxMutationLock` and prevent retired releases from writing afterward.

### Publication events

`KeypointSolutions\LaravelVox\Events\TranslationsPublished` fires after successful publication commits, including manual refresh and published-only regeneration. It exposes `frontendMode`, `publishedOnly`, and `result` (file/value/deletion counts). Sync, Compile, and Deploy do not emit it.

An application listener may queue a frontend build when `frontendMode === 'bundled'`. Vox does not run npm or deploy assets. Do not skip deletion-only events because their changed-value count is zero. Listener failures occur after publication and cannot undo it.

## Custom Routes

Set `VOX_ROUTES_PREFIX=admin/translations` to change the automatic `/vox` prefix. For application-owned route groups, set `VOX_ROUTES_AUTO_REGISTER=false` and mount the routes:

```php
use Illuminate\Support\Facades\Route;
use KeypointSolutions\LaravelVox\Facades\LaravelVox;

Route::middleware('auth')->prefix('admin/translations')->group(function (): void {
    LaravelVox::routes();
});
```

For a runtime-only application, `LaravelVox::translationRoutes('translations')` mounts only translation endpoints. `VOX_GUI_ENABLED=false` disables the GUI. Surrounding route middleware, domains, and prefixes are inherited.

## Reset and Recovery

Settings → Danger zone offers **Reset translations** (keeps settings, environments, and audits) and **Reset all Vox data**. Both permanently delete database translation work; published files, runtime catalogues, and configuration remain. Back up the database first. Sync can recover file values, not unpublished drafts.

CLI equivalents are `vox:reset` and `vox:reset --scope=all`. They require typed confirmation; non-interactive automation requires explicit `--force`.

## Development and Testing

```bash
composer install
npm install
composer --working-dir=test-app setup
composer test
```

`composer test` runs package tests, consumer tests, builds, and the test application's browser suites. For targeted runs, use `composer test:package`, `npm run test:consumer`, or `composer test:ui`.

## Credits and License

Created by [Costin Bereveanu](https://github.com/schniper), maintained by [Keypoint Solutions](https://keypoint.ro).

Contact: [costin@keypoint.ro](mailto:costin@keypoint.ro).

Copyright © 2026 Keypoint Solutions SRL. Licensed under the [MIT license](LICENSE.md).
