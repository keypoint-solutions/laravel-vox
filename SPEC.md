# Laravel Vox Living Specification

This document is the implementation source of truth for Laravel Vox. It records the intended product contracts, current implementation status, and the evidence required before a feature is considered complete.

Status values:

- **Complete** - implemented and covered by focused automated verification.
- **In progress** - implementation exists but acceptance checks are not all green.
- **Planned** - accepted objective with implementation still outstanding.
- **Deferred** - intentionally outside the current release scope.

## Product objective

Laravel Vox discovers Laravel translation usage, maintains translations in a reviewable database workflow, assists translation without breaking Laravel placeholders, and safely publishes or synchronizes translations between applications.

The package must support both sides of a consuming Laravel application:

- backend rendering through Laravel's native translator;
- frontend rendering through `laravel-vue-i18n`, using the same PHP and JSON language files.

A primary synchronization goal is deployment safety: translations edited by administrators in production must be pulled back into the developer's local application before a new deployment, so stale repository language files do not overwrite production-authored wording.

The local Laravel 13 test application is the executable integration example. It must remain accessible through Herd and its complete package plus browser verification must run with one root command:

```bash
composer test
```

The repository should follow a conventional Laravel package layout. The consumer test application is a normal Laravel 13 skeleton, not a second package workbench with custom symlink choreography. Package assets, database setup, and browser-test preparation belong in maintained setup commands rather than manual local steps.

## Project-wide objectives

| Objective                                                                                                                                      | Status   |
| ---------------------------------------------------------------------------------------------------------------------------------------------- | -------- |
| Use a conventional Laravel package plus Laravel 13 consumer test-app structure.                                                                | Complete |
| Remove redundant workbench code and manually maintained test-app symlinks.                                                                     | Complete |
| Keep the package UI accessible through the Herd-hosted consumer app.                                                                           | Complete |
| Keep PHP and Node dependencies current and lockfiles reproducible.                                                                             | Complete |
| Provide one root verification command for package, build, and browser coverage.                                                                | Complete |
| Review all advertised UI modules and remove or implement placeholders.                                                                         | Complete |
| Identify Laravel Vox in the UI as an open-source project offered by Keypoint Solutions, with company and GitHub links.                         | Complete |
| Provide a concise GitHub README covering requirements, installation, backend/frontend integration, configuration, workflows, and verification. | Complete |
| Finish the milestone with a clean, reviewed commit.                                                                                            | Complete |

## Core contracts

### Translation discovery

| Contract                                                                                                                       | Status   |
| ------------------------------------------------------------------------------------------------------------------------------ | -------- |
| Laravel backend helpers such as `__()` are detected as backend occurrences.                                                    | Complete |
| Vue/JavaScript calls such as `$t()`, `$wt()`, and `trans()` are detected as frontend occurrences.                              | Complete |
| A translation is marked `is_frontend` when its discovered source is frontend code.                                             | Complete |
| Stale frontend flags are removed on a later sync when a key is no longer used by frontend code.                                | Complete |
| Standard Laravel framework language sources and optional Cashier sources are scanned by default.                               | Complete |
| Dynamic keys are surfaced and can be protected from destructive cleanup.                                                       | Complete |
| Displayed timestamps are ISO values from the server and formatted in the browser's local timezone through a shared composable. | Complete |

### Frontend consumption

| Contract                                                                                                                                               | Status   |
| ------------------------------------------------------------------------------------------------------------------------------------------------------ | -------- |
| The package exposes a Vite plugin that prepares Laravel PHP translations through `laravel-vue-i18n`.                                                   | Complete |
| The package exposes a Vue plugin that selects the current `<html lang>`, loads the locale, and preserves `laravel-vue-i18n` parameter/plural handling. | Complete |
| JSON translations remain available to frontend consumers.                                                                                              | Complete |
| Only PHP translation groups marked for frontend use are included in the browser bundle.                                                                | Complete |
| Frontend groups are maintained automatically from scanner results.                                                                                     | Complete |
| Consuming applications may explicitly override the frontend group list.                                                                                | Complete |
| The test application provides equivalent Blade and Vue pages with a language picker.                                                                   | Complete |
| Backend-only translation groups are proven absent from the frontend bundle.                                                                            | Complete |

The intended standard integration is:

```js
// vite.config.js
import vox from '@keypoint-solutions/laravel-vox/vite';

export default defineConfig({
    plugins: [laravel({ input: ['resources/js/app.ts'] }), vue(), vox()],
});
```

```ts
// resources/js/app.ts
import { createVoxI18n } from '@keypoint-solutions/laravel-vox/vue';

createApp(App).use(createVoxI18n()).mount('#app');
```

The Vite plugin reads the generated Vox frontend manifest by default. `vox({ groups: [...] })` is the explicit override for applications that need a fixed list.

`laravel-vue-i18n` remains the frontend translation runtime. Vox must integrate with it rather than reimplement its parameter replacement, pluralization, or locale-loading behavior.

### Management workflow

| Contract                                                                                               | Status   |
| ------------------------------------------------------------------------------------------------------ | -------- |
| Workflow state (`pending` or `approved`) is independent from sync freshness (`new` or `updated`).      | Complete |
| Approving a translation does not change its content `updated_at` timestamp.                            | Complete |
| Approved filtering includes approved translations regardless of sync freshness.                        | Complete |
| Saving and moderation actions display an accessible success cue.                                       | Complete |
| Missing-value filtering honors the configured missing translation prefix.                              | Complete |
| Moderation actions are recorded in the audit trail.                                                    | Complete |
| Users can select individual or all visible translations and approve or return them to review in bulk.  | Complete |
| Bulk moderation preserves content timestamps and records one structured audit action.                  | Complete |
| Status dots and icon-only actions have hover and keyboard-focus tooltips.                              | Complete |
| Frontend/backend group and occurrence indicators explain their meaning without relying on color alone. | Complete |

### AI translation

| Contract                                                                                                                                        | Status   |
| ----------------------------------------------------------------------------------------------------------------------------------------------- | -------- |
| Provider-specific implementation is behind a package translation driver.                                                                        | Complete |
| Settings remain provider-neutral and allow future drivers.                                                                                      | Complete |
| The package keeps its own small driver contract rather than requiring `laravel/ai` for every installation.                                      | Complete |
| A future Claude driver can be added without changing the settings page contract.                                                                | Complete |
| OpenAI uses the Responses API.                                                                                                                  | Complete |
| Laravel bound parameters, markup, and line breaks are protected and restored.                                                                   | Complete |
| The OpenAI integration is live-tested with the configured `gpt-5.4-mini` model when credentials are available.                                  | Complete |
| Placeholder protection follows the newer `TranslateNewPhrases` approach reviewed in the sibling application.                                    | Complete |
| Available model discovery is provider-backed and constrained to supported translation models.                                                   | Complete |
| Provider-owned values such as model choices may be refreshed from the provider; arbitrary endpoint editing is not exposed as a routine setting. | Complete |

### Routing and settings

| Contract                                                                                   | Status   |
| ------------------------------------------------------------------------------------------ | -------- |
| Package routes can register automatically using the configured prefix.                     | Complete |
| Automatic route registration can be disabled.                                              | Complete |
| Applications can inject the complete package route set where desired.                      | Complete |
| Settings validate constrained choices and do not expose environment-owned credentials.     | Complete |
| The settings UI describes provider-neutral concepts even when OpenAI is the active driver. | Complete |

### Publish

| Contract                                                                                       | Status   |
| ---------------------------------------------------------------------------------------------- | -------- |
| The UI reports approved, pending, and incomplete translation counts.                           | Complete |
| Publishing writes approved database values to Laravel PHP and JSON language files.             | Complete |
| Publishing preserves existing PHP comments and obsolete-key comments.                          | Complete |
| Pending database changes do not overwrite language files.                                      | Complete |
| Publish activity and affected-file counts are audited.                                         | Complete |
| The UI reports a clear success result after publishing.                                        | Complete |
| The package does not execute an application's arbitrary deployment command from a web request. | Complete |

### Remote sync

| Contract                                                                                                              | Status   |
| --------------------------------------------------------------------------------------------------------------------- | -------- |
| Local sync scans current source and language files into the Vox database.                                             | Complete |
| Local sync can be triggered from both CLI and UI with the same service.                                               | Complete |
| A keyed endpoint can provide a language archive to another Vox application.                                           | Complete |
| Remote environments can be configured and triggered from the UI.                                                      | Complete |
| Remote archive extraction rejects unsafe paths.                                                                       | Complete |
| Remote sync pulls translations from a production, staging, or other Vox app into the local application for review.    | Complete |
| Production remote values replace matching local values while local-only keys are retained for merging.                | Complete |
| A later local Publish/build preserves the pulled production-admin edits for the next deployment.                      | Complete |
| A synchronized value that differs from an approved database value returns that translation to pending review.         | Complete |
| Unchanged approved translations remain approved after local or remote sync.                                           | Complete |
| Pulling and merging a remote archive refreshes the local Vox database through the same local-sync service as the CLI. | Complete |
| The test project exposes a headless fixture endpoint for visual and browser-test verification.                        | Complete |
| A second full Laravel UI application is not required for sync verification.                                           | Complete |
| Remote sync reports a clear success or failure result in the UI and audit trail.                                      | Complete |

### Audit

| Contract                                                                          | Status   |
| --------------------------------------------------------------------------------- | -------- |
| Commands and moderation actions write structured audit records.                   | Complete |
| The Audit page lists real activity with local-time formatting and useful context. | Complete |

## Settings contract

The settings page is a safe editor for supported package behavior, not a free-form mirror of every environment value.

- Secrets remain environment-owned and are represented only by configured/not-configured status.
- Provider credentials, locale discovery, and the base locale remain environment/configuration owned.
- The settings UI edits only the active provider's supported model, translation guidance, and remote-sync enablement.
- OpenAI model discovery may refresh the supported model list, with a maintained fallback catalog when discovery is unavailable.
- Low-level provider endpoints are package implementation details unless a future driver has a concrete, validated need to expose one.
- User feedback is visible after saving, refreshing provider data, or encountering a validation/provider error.

## Test application contract

The `test-app` directory serves three purposes:

1. prove Composer package discovery, migrations, routes, and published UI assets in a stock Laravel 13 application;
2. demonstrate real backend and frontend translation consumption through Blade and Vue pages with a language picker;
3. host deterministic browser fixtures, including a headless remote-translation archive endpoint.

The test app must not require:

- a duplicate management UI;
- manual symlink creation;
- hand-edited workbench boot code;
- a separately hosted second Laravel project for remote-sync testing.

Its setup command must install Composer and Node dependencies, create environment/database state, run package migrations/seed data, and build both the consumer and package assets.

## Test architecture

Browser coverage is split by user-facing domain rather than accumulated in a single catch-all file:

- translation consumer pages;
- management and moderation;
- settings;
- publish;
- remote sync;
- audit.

Each browser flow must assert page identity, meaningful content, no JavaScript errors, the primary interaction result, and the persisted or filesystem outcome where applicable. Lower-level feature tests own validation, query semantics, archive safety, and file-writing edge cases.

The required browser flows are:

| Domain   | Required interaction                                                                                |
| -------- | --------------------------------------------------------------------------------------------------- |
| Consumer | Switch locales and verify matching Blade and Vue PHP-group, JSON, nested, and parameterized values. |
| Manage   | Search/filter, edit/save, approve/reopen, inspect tooltips, and verify the Approved tab.            |
| Settings | Save constrained settings, refresh provider models, and observe success/error feedback.             |
| Publish  | Publish an approved value, observe success, and verify the resulting language file.                 |
| Sync     | Configure the headless fixture, pull it, observe success, and verify the imported translation.      |
| Audit    | Verify the preceding actions appear with locally formatted timestamps and useful context.           |

## Current milestone

The current milestone is complete when:

1. Blade and Vue consumer pages work in English, French, and Romanian through Herd.
2. Frontend bundles contain scanner-approved PHP groups plus JSON translations, but not backend-only groups.
3. Manage approval, saving feedback, status explanations, and filtering pass feature and browser tests.
4. Publish, Sync, and Audit are functional rather than placeholder pages.
5. The headless remote fixture demonstrates a real pull into the current test app.
6. `composer test` passes the package suite, both frontend builds, and all focused Pest Browser suites.
7. The final browser audit is captured and the completed work is committed.

## Accepted implementation decisions

- Workflow approval and source freshness are separate dimensions.
- `pending` is the review state for new translations and for previously approved values changed by local or remote synchronization.
- Frontend detection is automatic from scanned source calls; explicit frontend group configuration is an override.
- `laravel-vue-i18n` remains a dependency of the JavaScript integration.
- Vox exposes package-owned Vite and Vue entry points so consuming apps do not copy bootstrap logic.
- JSON translations are frontend-addressable; PHP group export is allow-listed by the frontend manifest.
- The package keeps a small provider-neutral translation-driver abstraction instead of adding `laravel/ai` as a mandatory Composer dependency.
- Remote-sync visual testing uses a deterministic headless fixture in the current Laravel 13 test app.
- Remote sync treats the selected production/staging app as authoritative for matching keys while retaining newly developed local-only keys.
- Publishing updates language artifacts only; application deployment remains the consuming application's responsibility.

## Verification log

| Check                                                    | Latest result                                                                                                   |
| -------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------- |
| Baseline package and browser suite before this milestone | 41 tests, 246 assertions; passed                                                                                |
| Manage and synchronization regression tests              | 16 tests, 148 assertions; passed                                                                                |
| Package feature and unit suite                           | 59 tests, 381 assertions; passed                                                                                |
| Consumer Vite build                                      | Passed; frontend PHP/JSON plus backend-only negative boundary verified                                          |
| Herd-hosted Pest Browser suite                           | 8 tests, 58 assertions; passed                                                                                  |
| Final `composer test`                                    | Passed: package tests, package build, asset publish, consumer build, and browser suite                          |
| Live Herd browser audit                                  | Passed: Manage bulk controls/tooltips/footer, Sync demo environment, and Vue bundle boundary; no console errors |
