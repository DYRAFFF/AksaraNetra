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

        if ($response->getStatusCode() === 204) {
            return $response;
        }

        $response->headers->set('x-content-type-options', 'nosniff');

        $contentType = (string) $response->headers->get('content-type', '');
        if (str_starts_with(strtolower($contentType), 'application/json')) {
            $response->headers->set('content-type', 'application/json; charset=utf-8');
        }

        $cacheControl = (string) $response->headers->get('cache-control', '');
        if ($cacheControl === '' || str_contains($cacheControl, 'no-cache') || str_contains($cacheControl, 'no-store')) {
            $response->headers->set('Cache-Control', 'no-store');
        }

        return $response;
    }
}
