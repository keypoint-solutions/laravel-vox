# Laravel Vox

[![Latest Version on Packagist](https://img.shields.io/packagist/v/keypoint-solutions/laravel-vox.svg?style=flat-square)](https://packagist.org/packages/keypoint-solutions/laravel-vox)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/keypoint-solutions/laravel-vox/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/keypoint-solutions/laravel-vox/actions?query=workflow%3Arun-tests+branch%3Amain)

Laravel Vox discovers translation usage, manages reviewed values in a dedicated database, publishes approved translations to Laravel language files, and safely pulls administrator-edited translations back from another application. Its optional AI driver protects Laravel placeholders, markup, and line breaks.

Laravel Vox was created by [Costin Bereveanu](https://github.com/schniper) and is maintained and offered by
[Keypoint Solutions](https://keypoint.ro).

## Frontend development feature

**Incremental translation hot reload:** editing one PHP language file reparses only that file and updates only its affected locale module. Other languages reuse their cached translations, keeping development feedback fast even with many locales. [How translation hot reload works](#incremental-translation-hot-reload).

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

- first-import file values are registered as approved application defaults, without an approval backlog;
- manual and AI draft edits are pending until accepted or approved;
- incoming local and remote changes can be inspected before accepting all or selected values; acceptance includes approval;
- approval applies per language, so accepting one language never approves another language's draft;
- database rows absent from both source code and language files are shown as `Orphan`;
- each approved non-empty value without a missing-value marker can publish independently; other languages do not block it.

From `/vox/manage`, translations can be edited, AI-translated individually, or selected in bulk to fill only missing
target values. New dynamic values can also AI-fill their missing target locales from the required source value before
they are created. Bulk AI results return to pending review; selected translations can then be approved or returned to
review together. Successful saves close the editor and appear in an accessible toast. `/vox/publish` writes approved
edited values and refreshes recorded published wording in PHP and JSON files, preserving unrelated drafts. It remains
available with no newly approved changes for manual refreshes. Published edits are persistent overrides of application wording.

## Dynamic translation keys

Dynamic application code can assemble a translation key at runtime:

```ts
$t(`enums.user_roles.${user.role}`);
```

Vox records supported template-string and concatenation expressions as wildcard patterns such as
`enums.user_roles.*`. A wildcard can span dots. Effective patterns are the union of:

- patterns detected by the latest scan;
- application-owned `vox.retained_keys`;
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
VOX_TRANSLATE_MODEL=gpt-5.6-luna
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

### Choose delivery and frontend groups

Delivery and translation selection are independent. Both modes select PHP groups using the frontend manifest;
switching delivery does not require sending every translation to the browser.

| Delivery          | `VOX_FRONTEND_RUNTIME_ENABLED` | After a manager publishes                                                           |
| ----------------- | ------------------------------ | ----------------------------------------------------------------------------------- |
| Bundled (default) | Unset or `false`               | Language files update. Rebuild and deploy the frontend to update its locale chunks. |
| Runtime           | `true`                         | Language files and server JSON catalogues update. No frontend rebuild is required.  |

With Composer integration, use `vox()` and import from `@laravel-vox/vue.js` in both development and production.
The plugin reads the same `VOX_FRONTEND_RUNTIME_ENABLED` environment setting as Laravel; no `VITE_` variable is
needed. Vite's mode-specific environment files and process environment apply. Keep the build environment and
deployed Laravel configuration aligned. Changing delivery mode requires restarting Vite or rebuilding the frontend.
An explicit `vox({ runtime: true })` or `vox({ runtime: false })` overrides the plugin's environment choice only;
it does not change Laravel's endpoint configuration. npm consumers must also choose the matching `/vue` or
`/vue/runtime` import shown below.

Frontend groups default to automatic identification from scanned frontend calls. In a local Laravel application,
the Vite plugin refreshes this selection automatically at startup, after source edits, and before production builds.
Identification currently selects **whole PHP groups**, not individual keys:
one frontend reference to `labels.save` includes all of `labels.php`. JSON translations are included without
per-key frontend filtering. `retained_keys` protects wording from cleanup; it does not by itself expose a PHP group
to the frontend.

To override automatic group selection for both delivery modes, set `VOX_FRONTEND_GROUPS_MODE=configured` and
`VOX_FRONTEND_GROUPS=labels,frontend`. Setting the value to `*` includes every PHP group under the application's
language directory, including nested files and namespaced overrides. Omit these overrides to use automatic selection.
The Vite-only `frontendGroups` option affects bundled delivery only. A missing or invalid manifest gives the bundler
no PHP groups; automatic discovery or parse/sync generates it.

### Automatic frontend group discovery

When `artisan` exists at Vite's root, `vox()` runs `vox:frontend-discover` before preparing translations. The command
reuses Vox's parser and its configured `parse.paths`, `parse.exclude`, and `parse.extensions`. It writes only the
frontend manifest, without querying the Vox database, importing values, modifying language files, or publishing.
Configured group selection takes precedence over source discovery.

During development, source additions, edits, and deletions trigger a debounced rescan. The manifest is rewritten
only when its groups change. The existing translation hot reload then updates bundled locale modules or recompiles
and refetches runtime catalogues. Adding a first frontend reference to a group, or removing its last reference,
therefore needs no manual sync. This minimal implementation rescans the configured sources rather than maintaining
an incremental source index. Application source changes still follow normal Vite reload behavior.

Production builds refresh the manifest before generating bundled locale chunks. In runtime mode they also run
`vox:compile` afterward to prepare current server catalogues. Deploy these generated catalogues with the application;
the JavaScript build does not upload them to the server. Discovery or compilation failures fail the build.

Use `vox({ frontendDiscovery: false })` when another process prepares the manifest. If Artisan is not available at
Vite's root, discovery is skipped; run `php artisan vox:frontend-discover` in the Laravel application before building
and transfer its manifest as needed. In runtime mode, also compile and deploy the catalogues. `phpBinary` selects the
PHP executable for both discovery and compilation. Restart Vite after changing parser paths or configuration.
Discovery selects groups; creating translation values and resolving ambiguous dynamic keys remain separate tasks.

### Incremental translation hot reload

The bundled Vite integration compiles PHP language files once at development startup or production build, then caches each parsed file. During development:

- Editing, adding, or deleting a PHP translation file rebuilds only its locale module from the cached files. Other locales are not reparsed or regenerated.
- Unchanged file contents are skipped. Edits that leave the exported translations unchanged, such as whitespace changes or changes to excluded backend groups, trigger no translation update.
- JSON language files are ordinary Vite modules; editing one does not recompile PHP translations.
- Adding or removing a locale updates the lazy-loader catalogue. Changing the frontend-group manifest refilters cached translations without parsing PHP again.
- Framework, application, namespaced vendor overrides, and optional `additionalLangPaths` retain their merge precedence. Later language roots override earlier values per key.

No generated `php_*.json` files are written, and no active-development-locale setting or application-specific reload plugin is needed. Vox accepts translation updates without remounting the Vue application, preserving open dialogs and unsaved form state. Reactive `$t` rendering and `wTrans` values update in place; strings translated once and copied into plain variables remain snapshots. Changes to application code or Vite configuration still follow normal Vite reload behavior.

### Runtime translation hot reload

With runtime delivery enabled, the Vox Vite plugin runs `php artisan vox:compile --no-interaction` at development
startup and after PHP or JSON language files or the frontend manifest change. Rapid saves are batched, and
compilations run one at a time. This recompiles the runtime catalogues from language files; it does not import,
approve, or publish database translations. Unlike bundled hot reload, compilation currently rebuilds all catalogues.

After a successful compilation, connected runtime clients refetch their loaded locale dictionaries and update
reactive translations in place, preserving open dialogs and unsaved forms. Compilation failures are reported in
the terminal and browser console; the browser retains its current translations and a subsequent save retries.

This requires the Vox Vite plugin, a local Laravel application with Artisan at Vite's root, and PHP on `PATH`.
Use `vox({ phpBinary: '/path/to/php' })` to select another executable, or `vox({ runtimeHotReload: false })`
to disable automatic compilation, for example when the runtime endpoint belongs to a separate server.
The watcher defaults to `lang` and `storage/vox/frontend.json`; any `langPath` and `manifestPath` overrides
must match the consuming application's PHP configuration. The compiler uses Laravel's frontend selection settings.

Automatic frontend discovery updates the manifest when source usage changes, unless `frontendDiscovery` is disabled.
Browser hot reload is inactive during production builds and does not change production publishing.

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
    plugins: [laravel({ input: ['resources/js/app.ts'] }), vue(), vox({ runtime: false })],
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

The frontend locale and translation endpoints do not query the Vox database. They resolve locales from application
configuration and published runtime catalogue files, then serve those files with cache validation. Manager-added
locales are available to runtime clients once their catalogues have been generated. Admin and publishing workflows
continue to use the Vox database.

npm consumers can use the Vox Vite plugin for automatic group discovery, build preparation, and runtime translation hot reload. Composer consumers can use
`vox({ runtime: true })` for automatic alias registration without translation bundling, then import
`createVox`, `trans`, and `useVox` from `@laravel-vox/runtime.js`.

When `runtime` is omitted, `vox()` reads `VOX_FRONTEND_RUNTIME_ENABLED` from Vite's environment files
and process environment, defaulting to bundled translations when unset. No `VITE_` variable is needed,
and this setting is not exposed to browser code. Explicit `runtime: true` or `runtime: false` takes precedence.
An application may explicitly choose different development behavior, but no development override is required.
Restart Vite after changing this environment setting.

For a custom route prefix, configure one base URL:

```ts
const vox = await createVox({ baseUrl: '/admin/translations' });
```

This loads `/admin/translations/locales` and `/admin/translations/translations/{locale}`.
Advanced integrations may override `localesEndpoint`, `endpoint` (a string or locale callback), and `fetcher`.
Explicit endpoint overrides take precedence over `baseUrl`.

Publish prepares validated JSON at `storage/vox/frontend-translations`; translation requests only read these
artifacts and support ETag revalidation. JSON translations and PHP groups in the frontend manifest are included,
while PHP groups excluded by the selection are not sent to the browser. Local `vox:sync` also refreshes these artifacts when runtime delivery
is enabled. A locale listed in the catalogue may not yet have prepared artifacts; publish or sync it before use.
In production, already-open pages cache their loaded locale dictionaries; publishing does not push changes into
them. Reload the page to fetch the current catalogue. During Vite development, the runtime hot reload integration
described above recompiles edited language files and refreshes connected clients automatically.

### Reacting to publication

Vox emits `KeypointSolutions\LaravelVox\Events\TranslationsPublished` after a successful publication has committed
its Vox database transaction and completed the file transaction. It covers the Publish page, `vox:publish`,
selected **Accept and publish** operations, and `vox:publish --published-only`. Manual publication with no changed
values also emits the event, so it can request a rebuild or refresh. Failed or rolled-back publication does not emit it.
Internal staging (`publishTo`), Sync, Compile, and Deploy do not emit this publication event.

The event contains:

- `frontendMode`: `'bundled'` or `'runtime'`, captured from `vox.frontend.runtime.enabled` at publication time.
  This is the configured server mode, not inspection of an existing Vite build or its explicit overrides.
- `result`: the `PublishResult`, including `values()`, `files()`, `frontendFiles()`, and `deletedKeys()`.
- `publishedOnly`: `true` for regeneration of recorded defaults and published overrides; otherwise `false`.

Register a listener in your application's service provider, or use Laravel's event listener discovery:

```php
use App\Jobs\RebuildFrontendTranslations;
use Illuminate\Support\Facades\Event;
use KeypointSolutions\LaravelVox\Events\TranslationsPublished;

Event::listen(TranslationsPublished::class, function (TranslationsPublished $event): void {
    if ($event->frontendMode === 'bundled') {
        RebuildFrontendTranslations::dispatch();
    }
});
```

`RebuildFrontendTranslations` is an application-owned job: implement it to invoke your build/deployment pipeline.
Use an asynchronous queue for expensive work. Vox does not run npm, assume a hosting platform, or deploy assets.
The application decides whether to coalesce requests, refresh caches, or notify another service. The event fires
for backend-only publications too; consumers may inspect the result if they need finer filtering. Deletion-only
or manual refresh publications must not be skipped solely because `values()` is zero.

Publication is already committed when listeners run. A synchronous listener exception propagates to the caller,
but does not undo published wording. A successful Publish in bundled mode means the language files are published;
frontend visibility still depends on the application's subsequent build and deployment.

Build-time bundling remains suitable for isolated, offline, or static SPAs. A cross-origin SPA may opt into runtime
loading with an absolute base URL or endpoint, but authentication and CORS remain the consuming application's
responsibility.

## Local and remote synchronization

Local sync imports the current application's language files into the Vox database. First imports register already-live defaults. Later differences appear in **Incoming translations**, preserving local wording and drafts until a decision is made:

```bash
php artisan vox:sync
```

Use `php artisan vox:sync --parse` to update language files from discovered source keys first, then import the result
with the same scan. In `/vox/sync`, **Sync local files** imports immediately; **Parse and sync** also updates the files. Both paths show results before any acceptance decision and refresh source occurrences and frontend metadata. Rows found in neither source nor
language files are retained as Orphans for deliberate review instead of silently disappearing. Draft values remain protected from file imports. Published overrides survive file imports and deployments; existing orphans retain their
normal publishing restriction.

### Adding a language

The **Application languages** section in `/vox/sync` provisions a locale from the configured base locale. It copies
all PHP and JSON translation families, including vendor namespaces, and then synchronizes the new files into Vox.
Locale identifiers such as `de-DE` are canonicalized to Laravel-friendly forms such as `de_DE`.

Without AI, source strings are copied with `VOX_MISSING_TRANSLATION_PREFIX`, keeping them visible in Manage as
missing. With **Translate with AI now**, the active translation driver translates the source strings before the
files are installed. Both paths register the generated files as application defaults and record an audit event. When
runtime frontend delivery is enabled, its per-locale artifacts are refreshed as part of the same successful action.

Provisioned locales supplement `vox.translate.locales.values` in Vox settings, so adding a language works even when
the application uses an explicit configured list. Applications may still add the locale to source-controlled config
when that is their preferred declaration.

Remote sync addresses production-edited translations. On the source application, generate a shared key:

```bash
php artisan vox:generate-sync-key
```

Configure that application URL and key under **Configured environments** in `/vox/sync`, then select **Pull now**.
Current Vox endpoints exchange published translation snapshots. Use **Pull drafts** or `vox:sync-remote --include-drafts` to explicitly fetch editable values instead. Version 2 snapshots distinguish this contract from older database snapshots; both installations must support it. Older published-file ZIP endpoints are also supported. Both formats create review candidates without changing local translation values, approvals,
language files, or frontend artifacts.

**Review remote changes** compares each environment, key, and locale independently:

| State                      | Meaning                                                                                         |
| -------------------------- | ----------------------------------------------------------------------------------------------- |
| Incoming                   | The remote value changed since the last agreement/review, or there is no local value yet.       |
| Conflict                   | Both sides changed, or the first comparison found different existing values without a baseline. |
| Local value kept / changed | The local wording differs while the remote value is unchanged or was explicitly rejected.       |
| Matching                   | Both sides currently contain the same wording.                                                  |
| No longer on remote        | The latest snapshot omitted a previously seen value; no local deletion is inferred.             |

Use **Accept remote**, **Keep local**, or **Edit merged value**. Accepting or editing approves only the selected language values in the local database. **Accept and publish** also writes just that accepted selection to files; unrelated drafts and approved work remain untouched.
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
reflected in language files. Resolve changes and publish accepted values, then rerun the check when needed. It only
checks the selected environment's current snapshot; it does not deploy the application or push values to the remote.

Remote archives reject absolute paths, traversal entries, and symbolic links. Secrets are never returned to the settings or environment UI.

The Sync page can also download a ZIP representing the exact files a Publish would produce, without changing the
local language directory. Import validates an entire Vox ZIP before merging its PHP and JSON files over the language
directory and deliberately does not start a database sync; run local sync when ready to review the imported values.

Publish, ZIP download, legacy remote ZIP pull, and ZIP import share a structural translation-file validator. PHP files must
return one literal, optionally nested array with string keys and string values; concatenated string literals are
also supported. Variables, interpolation,
function calls, includes, and other executable PHP are rejected before any validated archive is
applied.

## Deployment and published overrides

Use `php artisan vox:deploy --no-interaction` to initialize Vox on its first deployment and prepare translations on subsequent releases. It runs setup and package migrations, installs dashboard assets, imports shipped defaults without moderation, preserves published manager overrides and drafts, and prepares effective PHP/JSON files and frontend artifacts. It never pulls a remote environment.

Release activation belongs to the application's deployment tooling. Run `vox:deploy` from the target release before activation. For workflows that allow manager writes during deployment, the application can wrap translation preparation and its own activation callback in `app(VoxMutationLock::class)->run(...)` (using `KeypointSolutions\LaravelVox\Support\VoxMutationLock`). Call the command in the same PHP process so the nested lock is reused. The application must also prevent requests running in retired releases from writing afterward. Vox does not switch symlinks, track active application releases, or orchestrate application rollback.

Keep `storage/vox` persistent across releases, and never overwrite it from a build's storage directory. All processes that publish or activate a release must share the configured lock file. Compiled frontend translations are stored in `storage/vox/frontend-translations` by default and can be rebuilt from recorded defaults and published overrides. Enable runtime delivery for production frontends that must reflect manager publications without rebuilding JavaScript. With `vox({ runtime: true })`, existing `@laravel-vox/vue.js` imports resolve to the runtime consumer automatically.

`vox:deploy` requires freshly installed application translation files. It imports those files as the new defaults, then applies previously published manager overrides. A retry must reinstall the fresh source files before calling it again. Vox does not keep original-file snapshots or deployment history.

Use `php artisan vox:publish --no-interaction` to publish approved pending edits and deletions, matching the dashboard Publish action.

Use `php artisan vox:publish --published-only --no-interaction` to regenerate recorded defaults and published overrides without importing the current files. It does not approve or publish drafts or apply pending deletions. Application translation lookup remains entirely file-based; the database is used only when managing or generating translations. Generated frontend artifacts are disposable output under storage. Preserve the target installation's Vox storage rather than overwriting it with build-machine data.

Use `php artisan vox:compile --no-interaction` to rebuild frontend JSON from the current language files, using the configured locales and frontend groups. Compilation does not import or modify database translations, apply stored overrides, approve drafts, or modify source language files. It validates output and restores previous artifacts on failure. This is a general file compilation operation; application deployment tooling decides when to invoke it.

A manager can choose **Use application wording** for one language to remove its published override. Unpublished drafts remain separate. Missing release keys do not delete manager drafts or published overrides. Explicit translation deletion remains a separate operation.

### Inspecting imports from the command line

`vox:sync` and `vox:sync-remote` fetch data before asking for any review decision. Inspect file candidates with `vox:review`, or remote candidates with `vox:review --environment=ID`. Then use `--accept-all`, `--keep-all`, or `--accept-all --publish` on that review command. The Sync page supports individual and partial decisions. A selected source's published wording is fetched by default; drafts require an explicit option.

Set `VOX_DEFAULT_SYNC_ENVIRONMENT` to a configured environment ID to omit `--environment` on remote pulls. This is a convenience for development, not a prerequisite or safety check for deployment. Sources retain independent comparison baselines; no deployment revision history is introduced.

## Configuration highlights

The published `config/vox.php` controls:

- automatic or manual route registration;
- enabled dashboard features and middleware;
- database connection and language path;
- scan paths, exclusions, retained keys and dynamic-key bindings, output formatting, and missing-value marker;
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

### Usage classification and cleanup

Manage distinguishes **Static usage** (exact calls), **Dynamic usage** (detected patterns or bindings),
**Retained by rule** (configuration or Settings), and **Orphan** (absent from source and language files, with no matching rule).
These labels are independent of approval status and may overlap. The editor lists exact occurrences separately from
possible dynamic matches; a matching pattern does not prove that a particular key is used.

Use `vox.retained_keys` for explicit keys or wildcard retention rules. The default protects `auth.*`, `pagination.*`,
`passwords.*`, and `validation.*`. Additional Settings patterns also retain keys;
`vox.dynamic_keys.bindings` still enumerates runtime key families.

Delete individual or selected orphan keys and dynamic keys without direct static references from Manage. Deletion marks keys as pending in Vox. Files remain unchanged until Publish removes their values from language files and existing runtime catalogues in every locale. Sync and Parse preserve pending deletions; restore a key from the Pending deletion filter to cancel before publishing. Confirmation lists
the keys and locale-value count. Scheduling deletion rechecks current source and records an audit entry. Publish removes the pending database records and related reconciliation records after file updates succeed. A future scan, binding, or remote import may recreate deleted keys.

To stop managing a key while preserving its language-file values, use **Ignore in Vox**. Its database row and values remain available under the
**Ignored** filter, but sync and publishing skip its values. Parsing preserves existing ignored values without generating
new values for that key. Restore returns it to management; run Sync afterward to refresh its values and usage.

Ignore and Restore leave published files untouched. Deletion does not remove matching retention rules; compiled frontend bundles need rebuilding after file changes outside development. Database resets preserve published and runtime files and remove ignored records.
