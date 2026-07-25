<!DOCTYPE html>
<html
    lang="{{ str_replace('_', '-', app()->getLocale()) }}"
    data-requested-locale="{{ $requestedLocale }}"
    data-fallback-demo-locale="{{ $fallbackDemoLocale }}"
    data-fallback-locale="{{ $fallbackLocale }}"
>

<head>
    <meta charset="utf-8">
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1"
    >
    <title>Laravel Vox Vue consumer</title>
    @vite('resources/js/app.ts')
</head>

<body>
    <div id="app"></div>
</body>

</html>
