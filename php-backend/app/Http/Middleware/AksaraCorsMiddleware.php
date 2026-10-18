<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\AksaraConfig;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AksaraCorsMiddleware
{
    public function __construct(
        private readonly ?AksaraConfig $config = null
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $config = $this->config ?? app(AksaraConfig::class);
        $origin = $request->header('origin');

        if ($origin !== null && $origin !== '') {
            if (in_array($origin, $config->frontendOrigins, true)) {
                if ($request->isMethod('OPTIONS')) {
                    $response = response()->noContent();
                    $response->headers->remove('content-type');
                    $response->headers->remove('cache-control');
                    return $this->attachCorsHeaders($response, $origin);
                }

                $response = $next($request);
                return $this->attachCorsHeaders($response, $origin);
            }

            return response()->json([
                'error' => 'origin-not-allowed',
                'message' => 'Origin frontend tidak diizinkan.',
            ], 403, [
                'Content-Type' => 'application/json; charset=utf-8',
                'Cache-Control' => 'no-store',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        }

        if ($request->isMethod('OPTIONS')) {
            $response = response()->noContent();
            $response->headers->remove('content-type');
            $response->headers->remove('cache-control');
            return $response;
        }

        return $next($request);
    }

    private function attachCorsHeaders(Response $response, string $origin): Response
    {
        $response->headers->set('access-control-allow-origin', $origin);
        $response->headers->set('vary', 'Origin');
        $response->headers->set('access-control-allow-methods', 'GET, POST, PUT, DELETE, OPTIONS');
        $response->headers->set('access-control-allow-headers', 'Authorization, Content-Type');
        $response->headers->set('access-control-allow-credentials', 'true');
        $response->headers->set('access-control-max-age', '600');

        return $response;
    }
}
