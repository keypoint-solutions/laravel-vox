# Laravel Vox

[![Latest Version on Packagist](https://img.shields.io/packagist/v/keypoint-solutions/laravel-vox.svg?style=flat-square)](https://packagist.org/packages/keypoint-solutions/laravel-vox)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/keypoint-solutions/laravel-vox/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/keypoint-solutions/laravel-vox/actions?query=workflow%3Arun-tests+branch%3Amain)

Laravel Vox discovers translation usage, manages reviewed values in a dedicated database, publishes approved translations to Laravel language files, and safely pulls administrator-edited translations back from another application. Its optional AI driver protects Laravel placeholders, markup, and line breaks.

Laravel Vox was created by [Costin Bereveanu](https://github.com/schniper) and is maintained and offered by
[Keypoint Solutions](https://keypoint.ro).

## Requirements

- PHP 8.2–8.4
- Laravel 11, 12, or 13
- Inertia Laravel 2 or 3 for the bundled management UI
- Node.js and Vite only when using the optional Vue translation integration
- PHP's Zip extension for remote synchronization

## Installation

Install the Composer package, publish its configuration, and initialize its database and dashboard assets:

```bash
composer require keypoint-solutions/laravel-vox
php artisan vendor:publish --tag=vox-config
php artisan vox:install
```

The package uses a dedicated SQLite database at `storage/vox/vox.sqlite` by default. Configure `vox.database` if the application should use another connection or path.

After upgrading Laravel Vox, rerun `php artisan vox:install --force` to apply migrations and refresh the compiled dashboard assets.

## Authorization

The GUI is protected by the `viewVox` gate. Settings additionally require `manageVoxSettings`:

```php
use App\Models\User;
use Illuminate\Support\Facades\Gate;

Gate::define('viewVox', fn (User $user): bool => $user->is_admin);
Gate::define('manageVoxSettings', fn (User $user): bool => $user->is_admin);
```

Gate checks are bypassed in the local environment by default. Set `VOX_BYPASS_AUTH_IN_LOCAL=false` to require authorization locally too.

## Routes

The package automatically registers its UI and synchronization routes at `/vox`. Change the prefix with `VOX_ROUTES_PREFIX`.

To mount Vox inside an application's own route group, disable automatic registration:

```dotenv
VOX_ROUTES_AUTO_REGISTER=false
```

Then add the complete route set wherever it belongs:

```php
use Illuminate\Support\Facades\Route;
use KeypointSolutions\LaravelVox\Facades\LaravelVox;

Route::middleware('auth')
    ->prefix('admin/translations')
    ->group(function (): void {
        LaravelVox::routes();
    });
```

Surrounding middleware, domains, prefixes, and route-name prefixes are inherited. `LaravelVox::routes('admin/translations')` may also receive a prefix directly.

`LaravelVox::routes()` includes the optional frontend translation and locale-catalogue endpoints. Applications that
disable the GUI and only need runtime frontend translations can mount that route set alone:

```php
LaravelVox::translationRoutes('translations');
```

## Discovering and managing translations

Configure locales and scan paths in `config/vox.php`, then run:

```bash
php artisan vox:parse
php artisan vox:sync
```

`vox:parse` discovers static Laravel, Blade, JavaScript, TypeScript, and Vue translation calls and updates language files. The default scan includes application code, Laravel framework sources, and Cashier when installed. `vox:sync` imports current language values and source metadata into the review database.

The management UI separates workflow state from source freshness:

- new and changed values are `pending`;
- approved values changed by a later local or remote sync return to `pending`;
- unchanged approved values remain approved;
- database rows absent from both source code and language files are shown as `Orphan`;
- only complete approved translations are eligible for publishing.

From `/vox/manage`, translations can be edited, AI-translated individually, or selected in bulk to fill only missing target values. Bulk AI results return to pending review; selected translations can then be approved or returned to review together. Successful saves close the editor and appear in an accessible toast. `/vox/publish` writes complete approved values to PHP and JSON language files without publishing pending changes.

## Dynamic translation keys

Dynamic application code can assemble a translation key at runtime:

```ts
$t(`enums.user_roles.${user.role}`);
```

Vox records supported template-string and concatenation expressions as wildcard patterns such as
`enums.user_roles.*`. A wildcard can span dots. Effective patterns are the union of:

- patterns detected by the latest scan;
- application-owned `vox.dynamic_keys.patterns`;
- additional patterns maintained in `/vox/settings`;
- finite bindings registered by the application.

The default config includes `auth.*`, `pagination.*`, `passwords.*`, and `validation.*` because Laravel constructs
keys in those translation families at runtime. Concrete values covered by an open pattern can be added from
`/vox/manage`. The source-locale value is required; missing target values can then use the normal individual or bulk
AI workflow.

Use a finite binding when the possible suffixes are known. Vox seeds every bound key during Parse and treats any
other value as outside the binding:

```php
use App\Enums\UserRole;
use App\Vox\OrderStatusKeys;

'dynamic_keys' => [
    'patterns' => [
        'validation.*',
    ],
    'bindings' => [
        'enums.user_roles.*' => UserRole::class,
        'features.*' => ['search', 'export'],
        'orders.statuses.*' => OrderStatusKeys::class,
    ],
],
```

Backed enums use their values; unit enums use their case names. Container-resolved provider classes implement
`DynamicKeyProvider`:

```php
use KeypointSolutions\LaravelVox\DynamicKeyProvider;

final class OrderStatusKeys implements DynamicKeyProvider
{
    public function values(): iterable
    {
        return ['draft', 'submitted', 'paid'];
    }
}
```

For values that must be resolved at runtime, register a callback from an application service provider:

```php
use KeypointSolutions\LaravelVox\Facades\LaravelVox;

public function boot(): void
{
    LaravelVox::dynamicKeys(
        'features.*',
        fn (): array => array_keys(config('features', [])),
    );
}
```

Callbacks should be deterministic and side-effect free. They are registered at runtime instead of being placed in
`config/vox.php`, so `php artisan config:cache` remains safe.

Dynamic values stay active during cleanup and synchronization. Once complete and approved, they publish normally;
being dynamic is not a reason to preserve an older file value. Patterns discovered in frontend code also include
their PHP group in the frontend manifest. `vox.dynamic_keys` is the only configuration contract for open patterns
and finite bindings.

Automatic discovery deliberately covers statically understandable templates and concatenation. Arbitrary runtime
expressions cannot be enumerated reliably; use an explicit pattern or binding for those. General AST/data-flow
inference and opt-in runtime usage telemetry are future enhancements, not current requirements.

## AI translation

The settings UI is provider-neutral; credentials remain in the application environment. OpenAI is the included driver and uses the Responses API:

```dotenv
OPENAI_API_KEY=...
VOX_TRANSLATE_DRIVER=openai
VOX_TRANSLATE_MODEL=gpt-5.4-mini
```

Supported models are retrieved from the provider account and constrained by the package's text-model catalog. Project-specific terminology or tone guidance can be stored from `/vox/settings`.

The driver masks and restores Laravel parameters such as `:name`, `%count%`, `{value}`, and printf tokens while preserving markup and line breaks. A future provider can implement the package's small translation-driver contract without changing the settings UI.

## Vue frontend translations

Vox integrates with [`laravel-vue-i18n`](https://github.com/xiCO2k/laravel-vue-i18n) so the frontend uses the same Laravel PHP and JSON language files, including parameter replacement and pluralization.

You can use either the published npm package or the JavaScript sources already installed by Composer.

### npm package

Install the JavaScript package:

```bash
npm install @keypoint-solutions/laravel-vox
```

Add the Vox Vite plugin after Laravel and Vue:

```js
import vue from '@vitejs/plugin-vue';
import vox from '@keypoint-solutions/laravel-vox/vite';
import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    plugins: [laravel({ input: ['resources/js/app.ts'] }), vue(), vox()],
});
```

Install the Vue plugin:

```ts
import { createVoxI18n } from '@keypoint-solutions/laravel-vox/vue';
import { createApp } from 'vue';

import App from './App.vue';

createApp(App).use(createVoxI18n()).mount('#app');
```

`createVoxI18n()` boots `laravel-vue-i18n` for the application. Installing it with Vue makes `$t` available
in components and initializes the helpers re-exported from the same Vox entry point:

```ts
import { createVoxI18n, trans, transChoice } from '@keypoint-solutions/laravel-vox/vue';
```

Importing both the plugin and helpers from Vox guarantees that they share one initialized runtime. Do not omit the
`.use(createVoxI18n())` call.

### Composer vendor integration

For a Ziggy-style setup without a second Laravel Vox installation, install the frontend runtime:

```bash
npm install laravel-vue-i18n
```

Then import the Vite plugin from Composer's `vendor` directory and define a short alias for application code:

```js
// vite.config.js
import { fileURLToPath, URL } from 'node:url';

import vue from '@vitejs/plugin-vue';
import vox from './vendor/keypoint-solutions/laravel-vox/resources/js/consumer/vite.js';
import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    resolve: {
        alias: {
            '@laravel-vox': fileURLToPath(
                new URL('./vendor/keypoint-solutions/laravel-vox/resources/js/consumer', import.meta.url)
            ),
        },
    },
    plugins: [laravel({ input: ['resources/js/app.ts'] }), vue(), vox()],
});
```

Install the Vue plugin from that alias:

```ts
// resources/js/app.ts
import { createVoxI18n, trans } from '@laravel-vox/vue.js';
import { createApp } from 'vue';

import App from './App.vue';

createApp(App).use(createVoxI18n()).mount('#app');
```

No files need to be copied or linked into `node_modules`. The package repository's test application uses Composer's
local path repository, so its `vendor/keypoint-solutions/laravel-vox` entry is a development symlink; a normal
Composer installation contains the same importable files as regular vendor files.

The current locale is read from `<html lang>`. Laravel JSON translations remain available, while PHP groups are allow-listed by `storage/vox/frontend.json`. The manifest is generated from frontend occurrences found by `vox:parse` and refreshed by `vox:sync`.

Applications that prefer a fixed list can use `vox({ frontendGroups: ['frontend', 'checkout'] })` or configure:

```php
'frontend' => [
    'groups' => [
        'mode' => 'configured',
        'values' => ['frontend', 'checkout'],
    ],
],
```

### Runtime loading without a frontend rebuild

Same-origin Laravel and Inertia applications can load published translations from the backend instead of bundling
them with Vite. Enable the endpoint:

```dotenv
VOX_FRONTEND_RUNTIME_ENABLED=true
```

Use the runtime Vue entry point. The package exposes `/vox/locales`, so the application does not need to duplicate
its configured locale list:

```ts
import { createVoxI18n, fetchVoxLocales } from '@keypoint-solutions/laravel-vox/vue/runtime';

async function bootstrap() {
    const catalog = await fetchVoxLocales();

    createApp(App)
        .use(
            createVoxI18n({
                fallbackLocale: catalog.default_locale,
                locales: catalog.locales.map((locale) => locale.code),
            })
        )
        .mount('#app');
}

void bootstrap();
```

Composer-vendor consumers import `@laravel-vox/runtime.js` from the same alias shown above. No Vox Vite plugin is
needed for this mode. Publish prepares validated JSON at `storage/vox/frontend-translations`; requests to
`/vox/translations/{locale}` only read those artifacts and support ETag revalidation. JSON translations and the PHP
groups in the frontend manifest are included, while backend-only PHP groups remain private. The locale catalogue
also reports `has_runtime_translations`, allowing a picker to distinguish defined locales whose artifacts have not
yet been prepared.

If package routes use a custom prefix, pass both matching endpoints:

```ts
const catalog = await fetchVoxLocales({
    endpoint: '/admin/translations/locales',
});

createVoxI18n({
    locales: catalog.locales.map((locale) => locale.code),
    endpoint: '/admin/translations/translations/{locale}',
    localesEndpoint: '/admin/translations/locales',
});
```

Build-time bundling remains the safer default for isolated, offline, or static SPAs. A cross-origin SPA may opt into
runtime loading with an absolute endpoint or endpoint callback, but authentication and CORS remain the consuming
application's responsibility.

## Local and remote synchronization

Local sync imports the current application's language files into the Vox database:

```bash
php artisan vox:sync
```

Use `php artisan vox:sync --parse` to update language files from discovered source keys first, then import the result
with the same scan. In `/vox/sync`, choosing local sync asks whether to perform that file-updating step or import the
files as they are. Both paths refresh source occurrences and frontend metadata. Rows found in neither source nor
language files are retained as Orphans for deliberate review instead of silently disappearing.

### Adding a language

The **Application languages** section in `/vox/sync` provisions a locale from the configured base locale. It copies
all PHP and JSON translation families, including vendor namespaces, and then synchronizes the new files into Vox.
Locale identifiers such as `de-DE` are canonicalized to Laravel-friendly forms such as `de_DE`.

Without AI, source strings are copied with `VOX_MISSING_TRANSLATION_PREFIX`, keeping them visible in Manage as
missing. With **Translate with AI now**, the active translation driver translates the source strings before the
files are installed. Both paths return affected translations to pending review and record an audit event. When
runtime frontend delivery is enabled, its per-locale artifacts are refreshed as part of the same successful action.

Provisioned locales supplement `vox.translate.locales.values` in Vox settings, so adding a language works even when
the application uses an explicit configured list. Applications may still add the locale to source-controlled config
when that is their preferred declaration.

Remote sync addresses production-edited translations. On the source application, generate a shared key:

```bash
php artisan vox:generate-sync-key
```

Configure that application URL and key under **Configured environments** in `/vox/sync`, then pull the language
archive into the local files and review database.

Remote archives reject absolute paths, traversal entries, and symbolic links. Secrets are never returned to the settings or environment UI.

The Sync page can also download a ZIP representing the exact files a Publish would produce, without changing the
local language directory. Import validates an entire Vox ZIP before merging its PHP and JSON files over the language
directory and deliberately does not start a database sync; run local sync when ready to review the imported values.

Publish, ZIP download, remote pull, and ZIP import share a structural translation-file validator. PHP files must
return one literal, optionally nested array with string keys and string values. Variables, interpolation,
concatenation, function calls, includes, and other executable PHP are rejected before any validated archive is
applied.

## Configuration highlights

The published `config/vox.php` controls:

- automatic or manual route registration;
- enabled dashboard features and middleware;
- database connection and language path;
- scan paths, exclusions, dynamic-key patterns and bindings, output formatting, and missing-value marker;
- locales, base locale, AI driver, model, guidance, and provider credentials;
- frontend group auto-detection or explicit overrides;
- optional prebuilt runtime frontend artifacts, endpoint path, and middleware;
- remote sync enablement, key, and endpoint middleware.

Frontend groups and translation locales use the same explicit `mode` / `values` contract. Automatic discovery is the
default. To replace discovery with a fixed list, select `configured` mode and provide either one value or a
comma-separated list:

```dotenv
VOX_FRONTEND_GROUPS_MODE=configured
VOX_FRONTEND_GROUPS=frontend,checkout

VOX_TRANSLATE_LOCALES_MODE=configured
VOX_TRANSLATE_LOCALES=en,fr,ro
```

In `config/vox.php`, each `values` entry may instead be a normal PHP array.

## Development and testing

The repository includes a stock Laravel 13 consumer application with Blade and runtime-loaded Vue translation pages
plus a headless remote-sync fixture.

Initial setup:

```bash
composer install
npm install
composer --working-dir=test-app setup
```

After setup, one command runs package tests, both frontend builds, dashboard asset publishing, and all domain-focused Pest Browser suites:

```bash
composer test
```

## Credits

- [Costin Bereveanu](https://github.com/schniper) — creator
- [Keypoint Solutions](https://keypoint.ro) — project home and maintainer
- [All contributors](../../contributors)

Questions and project enquiries can be sent to [cbereveanu@gmail.com](mailto:cbereveanu@gmail.com).

## License

Copyright © 2026 Keypoint Solutions SRL.

Laravel Vox is open-sourced software licensed under the [MIT license](LICENSE.md). The license preserves the
copyright notice while allowing broad use, modification, and distribution.
