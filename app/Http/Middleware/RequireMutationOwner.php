<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RequireMutationOwner
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! app()->environment(['local', 'testing']) && $request->user() === null) {
            abort(403, 'Persistent mutation APIs require an authenticated owner outside local/testing.');
        }

        return $next($request);
    }
}
