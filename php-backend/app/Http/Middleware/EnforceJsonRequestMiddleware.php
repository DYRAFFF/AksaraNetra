<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\AksaraConfig;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnforceJsonRequestMiddleware
{
    public function __construct(
        private readonly ?AksaraConfig $config = null
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $config = app(AksaraConfig::class);

        if (in_array($request->method(), ['POST', 'PUT', 'PATCH'], true)) {
            $contentType = (string) $request->header('content-type', '');
            if (!str_starts_with(strtolower($contentType), 'application/json')) {
                return response()->json([
                    'error' => 'bad-request',
                    'message' => 'Gunakan Content-Type application/json.',
                ], 415, [
                    'Cache-Control' => 'no-store',
                    'X-Content-Type-Options' => 'nosniff',
                ], JSON_UNESCAPED_SLASHES);
            }

            $contentLength = (int) $request->header('content-length', 0);
            if ($contentLength > $config->maxBodyBytes) {
                return response()->json([
                    'error' => 'bad-request',
                    'message' => 'Body permintaan terlalu besar.',
                ], 413, [
                    'Cache-Control' => 'no-store',
                    'X-Content-Type-Options' => 'nosniff',
                ]);
            }

            $rawContent = $request->getContent();
            if (strlen($rawContent) > $config->maxBodyBytes) {
                return response()->json([
                    'error' => 'bad-request',
                    'message' => 'Body permintaan terlalu besar.',
                ], 413, [
                    'Cache-Control' => 'no-store',
                    'X-Content-Type-Options' => 'nosniff',
                ]);
            }

            if ($rawContent !== '') {
                json_decode($rawContent);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    return response()->json([
                        'error' => 'bad-request',
                        'message' => 'JSON tidak valid.',
                    ], 400, [
                        'Cache-Control' => 'no-store',
                        'X-Content-Type-Options' => 'nosniff',
                    ]);
                }
            }
        }

        return $next($request);
    }
}
