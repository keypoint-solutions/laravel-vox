<?php

namespace KeypointSolutions\LaravelVox\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class Authorize
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->allowsAccess($request)) {
            return $next($request);
        }

        abort(403);
    }

    protected function allowsAccess(Request $request): bool
    {
        if (config('vox.system.bypass_auth_in_local') && app()->environment('local')) {
            return true;
        }

        $user = $request->user();

        if ($user === null) {
            return false;
        }

        return Gate::forUser($user)->check('viewVox');
    }
}
