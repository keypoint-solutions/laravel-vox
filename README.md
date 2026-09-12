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
php artisan vox:setup
```

The package uses a dedicated SQLite database at `storage/vox/vox.sqlite` by default. Configure `vox.database` if the application should use another connection or path.

After upgrading Laravel Vox, rerun `php artisan vox:setup --force` to apply migrations and refresh the compiled dashboard assets.

`vox:setup` creates the SQLite database when needed, runs package migrations, and publishes dashboard assets.
Service-provider boot only registers the connection; it does not create files. The previous `vox:install` name
remains available as an alias. Existing configured database connections are respected.

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

`vox:parse` discovers static Laravel, Blade, JavaScript, TypeScript, and Vue translation calls and updates language
files. Conventional forms include Laravel's `__()` and `trans()` helpers, `@lang`, `Lang::get()` / `Lang::string()`,
translator instance calls, and their plural counterparts, plus Vue `trans()`, `wTrans()`, `$t()`, frontend `__()`,
and the plural helpers. A static `Lang::array('messages.options')` or translator `array()` call is registered as the
subtree pattern `messages.options.*`, preserving its concrete leaves without creating a string at the parent key. The
scanner also resolves PHP or Blade keys assembled entirely from concatenated string literals as one static key. The
default scan includes application code, Laravel framework sources, and Cashier when installed. `vox:sync` imports current
language values and source metadata into the review database.

### Scanner limitations

- Dynamically constructed `Lang::array()` or translator `array()` paths cannot identify one precise subtree. Cover
  them with an explicit dynamic pattern; static array calls are detected automatically.
- Existence checks such as `Lang::has()` and `Lang::hasForLocale()` do not retrieve a value and are not counted as
  translation occurrences.
- Custom wrapper functions and translator instances stored under arbitrary variable names cannot be inferred safely.
  Their keys should also appear in a supported call or be covered by a configured dynamic pattern or finite binding.

The management UI separates workflow state from source freshness:

- new and changed values are `pending`;
- approved values changed by local sync or an accepted remote review decision return to `pending`;
- unchanged approved values remain approved;
- database rows absent from both source code and language files are shown as `Orphan`;
- only complete approved translations are eligible for publishing.

From `/vox/manage`, translations can be edited, AI-translated individually, or selected in bulk to fill only missing
target values. New dynamic values can also AI-fill their missing target locales from the required source value before
they are created. Bulk AI results return to pending review; selected translations can then be approved or returned to
review together. Successful saves close the editor and appear in an accessible toast. `/vox/publish` writes complete
approved values to PHP and JSON language files without publishing pending changes.

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
AI workflow. Manage includes a Dynamic filter, and the creation panel can copy the selected pattern's stable prefix
to the clipboard before the concrete key is entered.

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

The default prompt favors natural, idiomatic target-language meaning and register rather than word-for-word source
mirroring. The driver masks and restores Laravel parameters such as `:name`, `%count%`, `{value}`, and printf tokens
while preserving markup and line breaks. A future provider can implement the package's small translation-driver
contract without changing the settings UI.

## Destructive reset

Settings → **Danger zone** offers two reset scopes, protected by the `manageVoxSettings` permission:

- **Reset translations** deletes all translation keys, values (including unpublished work), source occurrences, and remote reconciliation records. Saved settings, environments, and audit history remain. Environment pull status is cleared.
- **Reset all Vox data** additionally deletes saved settings, environments, and audit history. Settings revert to application configuration defaults. One new audit event records the reset.

**Both actions are irreversible. Back up the Vox database first. Published language files are never changed or deleted.**
Runtime translation files, generated manifests, application configuration, and application credentials also remain untouched.
A subsequent local sync can import published translations again; it cannot recover unpublished work. Saved remote-environment
credentials are removed with the environment records in a full reset.

The UI requires the exact phrase `RESET TRANSLATIONS` or `RESET ALL VOX DATA` for the selected scope. The same
scopes are available from the command line:

```bash
php artisan vox:reset
php artisan vox:reset --scope=all
```

Both commands show destructive-action warnings and require the corresponding typed phrase. Non-interactive runs
refuse to reset unless `--force` is explicitly supplied. **`--force` skips confirmation and permanently deletes the
selected data**, so use it only for intentional automation.

The reset deletes rows within one transaction on the configured Vox connection. It preserves the schema and migration
history and does not reset auto-increment counters. The audit event is part of the same transaction.

## Vue frontend translations

Vox integrates with `laravel-vue-i18n` so the frontend uses the same Laravel PHP and JSON language files, including parameter replacement and pluralization.

### npm setup with bundled translations

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

Prepare translations before mounting:

```ts
import { createVox } from '@keypoint-solutions/laravel-vox/vue';
import { createApp } from 'vue';
import App from './App.vue';

async function bootstrap() {
    const vox = await createVox();

    createApp(App).use(vox).mount('#app');
}

void bootstrap();
```

### Translating in components

Use `$t()` in templates and `trans()` / `transChoice()` in scripts:

```vue
<script setup>
    import { trans, transChoice } from '@keypoint-solutions/laravel-vox/vue';

    function confirmationMessage() {
        return trans('frontend.Saved');
    }
</script>

<template>
    <h1>{{ $t('frontend.Welcome, :name', { name: 'Ana' }) }}</h1>
    <p>{{ transChoice('frontend.Items selected', 2) }}</p>
</template>
```

For runtime loading, import helpers from `/vue/runtime` instead. Always import the plugin and helpers from the same
entry point so they share the initialized runtime. Installing Vox registers `$t` and `$tChoice` on the Vue app,
replacing any previous globals with those names. `trans` is an imported function, not a global override.

Existing `createVoxI18n()`, `trans_choice()`, `wTrans()`, and `wTransChoice()` remain supported.
A `trans()` call returns a string; use it inside `computed()` when a value defined in script setup must react to locale changes.

### Selecting and switching locales

Pass a locale from your application or an ordered list of browser preferences:

```ts
const vox = await createVox({ locale: 'ro' });
// Or opt into browser language preferences:
const vox = await createVox({ locale: navigator.languages });
```

Vox tries each preference in order, matching the full locale first, then progressively less specific forms
(`fr-CA` → `fr`). Matching ignores case and accepts hyphens or underscores. If none match, it tries `<html lang>`,
then the fallback locale, then the first available locale. Without `locale`, the page language is used first.
Browser detection is opt-in. Runtime loading uses the catalogue default as its fallback; bundled loading defaults
to `en`. Set `fallbackLocale` to override this selection fallback.

Use the composable to read reactive state and switch the current locale:

```ts
import { useVox } from '@keypoint-solutions/laravel-vox/vue';

const { locale, locales, setLocale } = useVox();

await setLocale('ro');
// Ordered preferences work when switching too:
await setLocale(navigator.languages);
```

`locale` is a readonly ref and `locales` is a readonly computed array of supported locale codes.
Switching loads translations and updates `<html lang>`. Persistence is opt-in (see below). It does not navigate or change
Laravel's request locale. The consuming application handles those decisions. Vox uses `laravel-vue-i18n`'s shared
runtime; configure one translation runtime per application. Initialization and switching reject failed loads so
applications can handle errors with their normal bootstrap or notification flow.

### Remembering a locale

Enable persistence to remember deliberate language switches:

```ts
const vox = await createVox({
    locale: navigator.languages,
    persist: 'local',
});
```

`persist` defaults to `false`. Use `'local'` to remember the choice across visits, or `'session'` to remember it for
the tab's session. Both use `laravel-vox.locale` as the default storage key; override `storageKey` for applications
sharing an origin.

An explicit `locale` string overrides storage. Otherwise, a saved locale is matched against supported locales before
the ordered preferences, page language, and fallback. Unsupported saved values are skipped. Only a successful
`setLocale()` or `setVoxLocale()` saves the resulting active locale; initialization does not save an automatically
selected language. Unavailable, blocked, or full storage does not prevent initialization or switching.
These options work with both bundled and runtime translations.

### Composer vendor integration

For a Ziggy-style setup using the sources already installed by Composer:

```bash
npm install laravel-vue-i18n
```

Import the Vite plugin from Composer's `vendor` directory:

```js
import vox from './vendor/keypoint-solutions/laravel-vox/resources/js/consumer/vite.js';

// Include alongside your existing Laravel and Vue plugins:
plugins: [laravel({ input: ['resources/js/app.ts'] }), vue(), vox()];
```

The plugin registers the `@laravel-vox` alias automatically and deduplicates Vue and `laravel-vue-i18n`.
Application imports become:

```ts
import { createVox, trans, useVox } from '@laravel-vox/vue.js';
```

No manual Vite alias, copied files, or links into `node_modules` are needed. TypeScript projects using Composer
sources may still need a matching `paths` entry for their editor; Vite aliases only configure the bundler.

Laravel JSON translations remain available. PHP groups are allow-listed by `storage/vox/frontend.json`, generated
from frontend occurrences found by `vox:parse` and refreshed by `vox:sync`. For a fixed list, use
`vox({ frontendGroups: ['frontend', 'checkout'] })` or configure:

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

Use the runtime entry point with the same initialization API:

```ts
import { createVox } from '@keypoint-solutions/laravel-vox/vue/runtime';

async function bootstrap() {
    const vox = await createVox({ locale: navigator.languages });

    createApp(App).use(vox).mount('#app');
}

void bootstrap();
```

This awaits the locale catalogue, resolves the preferred locale, and prepares translations before mounting.
Omit `locale` to use the server-rendered page language. Pass `locales: ['en', 'fr']` to skip catalogue discovery;
in that case `fallbackLocale` defaults to `en`. `fetchVoxLocales()` remains available for applications needing the
full catalogue, including locale names and `has_runtime_translations`.

npm consumers do not need the Vox Vite plugin in runtime mode. Composer consumers can use
`vox({ runtime: true })` for automatic alias registration without translation bundling, then import
`createVox`, `trans`, and `useVox` from `@laravel-vox/runtime.js`.

For a custom route prefix, configure one base URL:

```ts
const vox = await createVox({ baseUrl: '/admin/translations' });
```

This loads `/admin/translations/locales` and `/admin/translations/translations/{locale}`.
Advanced integrations may override `localesEndpoint`, `endpoint` (a string or locale callback), and `fetcher`.
Explicit endpoint overrides take precedence over `baseUrl`.

Publish prepares validated JSON at `storage/vox/frontend-translations`; translation requests only read these
artifacts and support ETag revalidation. JSON translations and PHP groups in the frontend manifest are included,
while backend-only PHP groups remain private. Local `vox:sync` also refreshes these artifacts when runtime delivery
is enabled. A locale listed in the catalogue may not yet have prepared artifacts; publish or sync it before use.

Build-time bundling remains suitable for isolated, offline, or static SPAs. A cross-origin SPA may opt into runtime
loading with an absolute base URL or endpoint, but authentication and CORS remain the consuming application's
responsibility.

## Local and remote synchronization

Local sync imports the current application's language files into the Vox database:

```bash
php artisan vox:sync
```

Use `php artisan vox:sync --parse` to update language files from discovered source keys first, then import the result
with the same scan. In `/vox/sync`, choosing local sync asks whether to perform that file-updating step or import the
files as they are. Both paths refresh source occurrences and frontend metadata. Rows found in neither source nor
language files are retained as Orphans for deliberate review instead of silently disappearing. Accepted remote values
remain protected from file imports and orphan classification until Publish writes them; existing orphans retain their
normal publishing restriction.

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

Configure that application URL and key under **Configured environments** in `/vox/sync`, then select **Pull now**.
Current Vox endpoints exchange database snapshots, including unpublished administrator edits. Older ZIP endpoints
are also supported. Both formats create review candidates without changing local translation values, approvals,
language files, or frontend artifacts.

**Review remote changes** compares each environment, key, and locale independently:

| State                      | Meaning                                                                                         |
| -------------------------- | ----------------------------------------------------------------------------------------------- |
| Incoming                   | The remote value changed since the last agreement/review, or there is no local value yet.       |
| Conflict                   | Both sides changed, or the first comparison found different existing values without a baseline. |
| Local value kept / changed | The local wording differs while the remote value is unchanged or was explicitly rejected.       |
| Matching                   | Both sides currently contain the same wording.                                                  |
| No longer on remote        | The latest snapshot omitted a previously seen value; no local deletion is inferred.             |

Use **Accept remote**, **Keep local**, or **Edit merged value**. Accepting or editing changes only the local database
and returns the translation to pending approval. Approve it in Manage, then Publish to update the language files.
Keeping local wording acknowledges and rejects that remote version, so an unchanged pull does not reopen it.
Repeated unresolved pulls do not advance the review baseline. A new remote edit can require review again.

Filter by environment, change type, language, or wording. Bulk acceptance and rejection work on selected rows across
pages or **all matching changes**, not just the visible page. Every batch is atomic and audited. If local values or
remote candidates changed after loading the review, the entire stale decision is rejected; refresh before retrying.
Bulk acceptance across environments that disagree on the same value is rejected. Filter to one environment first.
Configure a remote language locally before accepting its values. Removals are retained for information rather than
automatically deleting local keys. Changing an environment URL clears that environment's comparison history.

Deployment scripts can pull and check an environment without an interactive prompt:

```bash
php artisan vox:sync-remote --environment=1 --check --no-interaction
```

This command exits unsuccessfully on a failed pull, incoming changes, conflicts, or local review values not yet
reflected in language files. Resolve changes, approve and publish accepted values, then rerun the check before deployment. It only
checks the selected environment's current snapshot; it does not deploy the application or push values to the remote.

Remote archives reject absolute paths, traversal entries, and symbolic links. Secrets are never returned to the settings or environment UI.

The Sync page can also download a ZIP representing the exact files a Publish would produce, without changing the
local language directory. Import validates an entire Vox ZIP before merging its PHP and JSON files over the language
directory and deliberately does not start a database sync; run local sync when ready to review the imported values.

Publish, ZIP download, legacy remote ZIP pull, and ZIP import share a structural translation-file validator. PHP files must
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
