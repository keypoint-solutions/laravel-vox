# Laravel Vox

Laravel Vox discovers translation keys, reviews translations in a dedicated database, and publishes approved wording to Laravel language files. It includes a management UI, optional AI translation, and a Vue integration.

**Application translations remain file-based.** Vox's database stores drafts, approvals, published overrides, and review decisions; normal Laravel translation lookups do not query it.

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

## Commands

| Command                        | Effect                                                                                          |
| ------------------------------ | ----------------------------------------------------------------------------------------------- |
| `vox:setup --force`            | Initialize/migrate Vox and publish manager assets                                               |
| `vox:parse`                    | Scan and update language files; no database translation import. `-v` shows detailed occurrences |
| `vox:sync`                     | Compare local files with database wording and refresh source metadata                           |
| `vox:sync --parse`             | Parse before syncing using the same scan                                                        |
| `vox:translate`                | AI-translate missing values **directly in files**                                               |
| `vox:publish`                  | Publish approved changes and pending deletions                                                  |
| `vox:publish --published-only` | Regenerate recorded defaults/overrides; do not publish drafts or deletions                      |
| `vox:compile`                  | Rebuild frontend JSON from current files; no database import or source-file changes             |
| `vox:frontend-discover`        | Refresh frontend group discovery                                                                |
| `vox:deploy`                   | Setup, import fresh release defaults, and restore published overrides                           |

Examples:

```bash
php artisan vox:translate --path=en/admin/messages.php
php artisan vox:translate --key=messages.welcome --force
php artisan vox:review
```

`--force` retranslates completed targets but still requires usable source wording. CLI translation and Manage share eligibility rules, but CLI writes files while Manage saves drafts. Use `php artisan help COMMAND` for complete options.

## Remote Sync and Archives

On the source application, run `php artisan vox:generate-sync-key`. Add its URL and key under Sync → **Configured environments**, then pull published values for review. Pulling drafts is explicit. Current endpoints exchange published snapshots; legacy ZIP sources are also supported.

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
