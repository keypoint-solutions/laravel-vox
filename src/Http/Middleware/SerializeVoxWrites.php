<?php

namespace KeypointSolutions\LaravelVox\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use KeypointSolutions\LaravelVox\Support\VoxMutationConflict;
use KeypointSolutions\LaravelVox\Support\VoxMutationLock;
use Symfony\Component\HttpFoundation\Response;

class SerializeVoxWrites
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethodSafe()) {
            return $next($request);
        }

        try {
            return app(VoxMutationLock::class)->run(fn (): Response => $next($request));
        } catch (VoxMutationConflict $exception) {
            throw ValidationException::withMessages(['vox' => $exception->getMessage()]);
        }
    }
}
