<?php

namespace KeypointSolutions\LaravelVox\Http\Controllers;

use Illuminate\Http\Request;
use KeypointSolutions\LaravelVox\Support\VoxLocaleCatalog;
use KeypointSolutions\LaravelVox\Support\VoxLocaleResolver;
use Symfony\Component\HttpFoundation\Response;

class FrontendLocalesController
{
    public function __invoke(Request $request, VoxLocaleCatalog $catalog, VoxLocaleResolver $localeResolver): Response
    {
        abort_unless(config('vox.frontend.runtime.enabled', false), 404);

        $payload = $catalog->all($localeResolver->resolveRuntimeLocales());
        $contents = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $etag = '"'.sha1($contents).'"';
        $headers = [
            'Cache-Control' => 'public, no-cache',
            'ETag' => $etag,
            'Vary' => 'Accept-Encoding',
        ];

        if ($request->headers->get('If-None-Match') === $etag) {
            return response('', 304, $headers);
        }

        return response()->json($payload, 200, $headers);
    }
}
