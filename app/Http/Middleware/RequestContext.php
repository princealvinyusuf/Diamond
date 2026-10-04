<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

final class RequestContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = $request->header('X-Request-ID');
        if (! is_string($requestId) || ! preg_match('/^[A-Za-z0-9._:-]{1,128}$/', $requestId)) {
            $requestId = (string) Str::uuid();
        }

        Log::withContext(['request_id' => $requestId]);
        $started = hrtime(true);
        $response = $next($request);
        $response->headers->set('X-Request-ID', $requestId);
        Log::info('http.request_completed', [
            'method' => $request->method(),
            'route' => $request->route()?->getName() ?: $request->path(),
            'status' => $response->getStatusCode(),
            'duration_ms' => (int) ((hrtime(true) - $started) / 1_000_000),
        ]);

        return $response;
    }
}
