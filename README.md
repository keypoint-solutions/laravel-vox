# A Laravel translation manager with AI backing and a friendly management GUI.

[![Latest Version on Packagist](https://img.shields.io/packagist/v/keypoint-solutions/laravel-vox.svg?style=flat-square)](https://packagist.org/packages/keypoint-solutions/laravel-vox)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/keypoint-solutions/laravel-vox/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/keypoint-solutions/laravel-vox/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/keypoint-solutions/laravel-vox/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/keypoint-solutions/laravel-vox/actions?query=workflow%3A"Fix+PHP+code+style+issues"+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/keypoint-solutions/laravel-vox.svg?style=flat-square)](https://packagist.org/packages/keypoint-solutions/laravel-vox)

This package provides a friendly GUI for managing translations in your Laravel application. It supports AI translations using your own OpenAI API key.

## Features

- Command that parses code to identify translatable phrases
- Define remote environments and pull translations from them
- Translate phrases manually or using AI
- Moderate translations
- Run builds to deploy JS frontend translations from the UI
- Provide context to AI and human reviewers to improve accuracy

## Support us

We invest a lot of resources into creating quality packages.

We highly appreciate you sending us a postcard from your hometown, mentioning which of our package(s) you are using. You'll find our address on [our contact page](https://spatie.be/about-us). We publish all received postcards on [our virtual postcard wall](https://spatie.be/open-source/postcards).

## Installation

You can install the package via composer:

```bash
composer require keypoint-solutions/laravel-vox
```

You can publish the config file with:

```bash
php artisan vendor:publish --tag="vox-config"
```

Optionally, you can publish the views using

```bash
php artisan vendor:publish --tag="vox-views"
```

## Documentation

Full documentation lives in [docs/README.md](docs/README.md).

## Authorization

Vox's GUI routes are protected by the `viewVox` gate, similar to Horizon. The settings page additionally requires `manageVoxSettings`. Define both in your application's `App\Providers\AuthServiceProvider`, in the `boot` method:

```php
use App\Models\User;
use Illuminate\Support\Facades\Gate;

Gate::define('viewVox', function (User $user): bool {
    return in_array($user->email, [
        'admin@example.com',
    ], true);
});

Gate::define('manageVoxSettings', function (User $user): bool {
    return $user->is_admin;
});
```

By default, the gate checking is skipped in the local environment.
You can disable this behavior by setting `system.bypass_auth_in_local` to `false` in your `config/vox.php` file.

## Routes

Vox registers its routes automatically at `/vox` by default. Change the prefix with `VOX_ROUTES_PREFIX`.

To place the routes in your application's own route groups, disable automatic registration:

```dotenv
VOX_ROUTES_AUTO_REGISTER=false
```

Then call the package helper from any route file:

```php
use Illuminate\Support\Facades\Route;
use KeypointSolutions\LaravelVox\Facades\LaravelVox;

Route::middleware('auth')
    ->prefix('admin/translations')
    ->group(function (): void {
        LaravelVox::routes();
    });
```

The helper inherits surrounding route prefixes, middleware, domains, and name prefixes. You can also pass a prefix directly, such as `LaravelVox::routes('admin/translations')`. Vox keeps applying the middleware configured in `vox.system.middleware`.

## Usage

```php

```

## Testing

```bash
composer test
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## Security Vulnerabilities

Please review [our security policy](../../security/policy) on how to report security vulnerabilities.

## Credits

- [Costin Bereveanu](https://github.com/keypoint-solutions)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
