<?php

namespace KeypointSolutions\LaravelVox\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\File;
use KeypointSolutions\LaravelVox\Support\VoxLocaleResolver;
use KeypointSolutions\LaravelVox\Translation\FrontendTranslationArtifacts;

class FrontendTranslationsController
{
    public function __invoke(
        Request $request,
        string $locale,
        VoxLocaleResolver $localeResolver,
        FrontendTranslationArtifacts $artifacts,
    ): Response {
        abort_unless(config('vox.frontend.runtime.enabled', false), 404);
        abort_unless(in_array($locale, $localeResolver->resolveLocales(), true), 404);

        $path = $artifacts->pathForLocale($locale);
        abort_unless(File::isFile($path), 404);

        $etag = '"'.sha1_file($path).'"';
        $headers = [
            'Cache-Control' => 'public, no-cache',
            'Content-Type' => 'application/json; charset=UTF-8',
            'ETag' => $etag,
            'Last-Modified' => gmdate('D, d M Y H:i:s', File::lastModified($path)).' GMT',
            'Vary' => 'Accept-Encoding',
        ];

        if ($request->headers->get('If-None-Match') === $etag) {
            return response('', 304, $headers);
        }

        return response(File::get($path), 200, $headers);
    }
}
