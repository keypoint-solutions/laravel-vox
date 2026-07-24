# Laravel Vox

[![Latest Version on Packagist](https://img.shields.io/packagist/v/keypoint-solutions/laravel-vox.svg?style=flat-square)](https://packagist.org/packages/keypoint-solutions/laravel-vox)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/keypoint-solutions/laravel-vox/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/keypoint-solutions/laravel-vox/actions?query=workflow%3Arun-tests+branch%3Amain)

Laravel Vox discovers translation usage, manages reviewed values in a dedicated database, publishes approved translations to Laravel language files, and safely pulls administrator-edited translations back from another application. Its optional AI driver protects Laravel placeholders, markup, and line breaks.

Laravel Vox is an open-source project offered by [Keypoint Solutions](https://keypoint.ro).

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
- only complete approved translations are eligible for publishing.

From `/vox/manage`, translations can be edited, AI-translated, selected in bulk, approved, or returned to review. `/vox/publish` writes complete approved values to PHP and JSON language files without publishing pending changes.

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

The current locale is read from `<html lang>`. Laravel JSON translations remain available, while PHP groups are allow-listed by `storage/vox/frontend.json`. The manifest is generated from frontend occurrences found by `vox:parse` and refreshed by `vox:sync`.

Applications that prefer a fixed list can use `vox({ groups: ['frontend', 'checkout'] })` or set `vox.frontend.groups` explicitly.

## Local and remote synchronization

Local sync imports the current application's language files into the Vox database:

```bash
php artisan vox:sync
```

It is also available from `/vox/sync`.

Remote sync addresses production-edited translations. On the source application, generate a shared key:

```bash
php artisan vox:generate-sync-key
```

This enables the keyed `POST /vox/sync` archive endpoint. In the receiving application's Sync page, add the source URL and key, then pull it. Matching remote values are authoritative, local-only keys are retained, and changed approved translations return to pending review before the next publish or deployment.

Remote archives reject absolute paths, traversal entries, and symbolic links. Secrets are never returned to the settings or environment UI.

## Configuration highlights

The published `config/vox.php` controls:

- automatic or manual route registration;
- enabled dashboard features and middleware;
- database connection and language path;
- scan paths, exclusions, protected/dynamic keys, output formatting, and missing-value marker;
- locales, base locale, AI driver, model, guidance, and provider credentials;
- frontend group auto-detection or explicit overrides;
- remote sync enablement, key, and endpoint middleware.

## Development and testing

The repository includes a stock Laravel 13 consumer application with Blade and Vue translation pages plus a headless remote-sync fixture.

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

- [Costin Bereveanu](https://github.com/keypoint-solutions)
- [Keypoint Solutions](https://keypoint.ro)
- [All contributors](../../contributors)

## License

Laravel Vox is open-sourced software licensed under the [MIT license](LICENSE.md).
