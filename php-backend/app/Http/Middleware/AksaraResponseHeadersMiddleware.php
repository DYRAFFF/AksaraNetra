<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AksaraResponseHeadersMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('x-content-type-options', 'nosniff');

        $cacheControl = (string) $response->headers->get('cache-control', '');
        // Default Laravel responses set 'no-cache, private'. For standard API responses, enforce pure 'no-store'.
        if ($cacheControl === '' || $cacheControl === 'no-cache, private' || $cacheControl === 'no-store, private') {
            $response->headers->removeCacheControlDirective('private');
            $response->headers->removeCacheControlDirective('no-cache');
            $response->headers->addCacheControlDirective('no-store', true);
        }

        return $response;
    }
}
