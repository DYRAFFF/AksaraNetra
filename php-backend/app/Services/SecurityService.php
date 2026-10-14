<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\Request;

class SecurityService
{
    /**
     * Convert binary string to base64url encoding (RFC 4648 §5, without padding).
     */
    public static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * Generate a cryptographically secure random token in base64url format.
     */
    public static function randomToken(int $bytes = 32): string
    {
        return self::base64UrlEncode(random_bytes($bytes));
    }

    /**
     * Generate a random job ID prefixed with 'aud_' followed by 18 random bytes (base64url).
     */
    public static function randomJobId(): string
    {
        return 'aud_' . self::base64UrlEncode(random_bytes(18));
    }

    /**
     * Compute HMAC-SHA256 hex digest for a value using the given secret.
     */
    public static function digest(string|int|float $value, string $secret): string
    {
        return hash_hmac('sha256', (string) $value, $secret);
    }

    /**
     * Derive a share token for a job using HMAC-SHA256 of "share:{jobId}:{accessToken}".
     * Returns base64url encoded raw digest.
     */
    public static function deriveShareToken(string|array $jobId, ?string $accessToken = null, ?string $secret = null): string
    {
        if (is_array($jobId)) {
            $secret = $jobId['secret'] ?? $secret;
            $accessToken = $jobId['accessToken'] ?? $accessToken;
            $jobId = $jobId['jobId'] ?? '';
        }

        if ($accessToken === null || $secret === null) {
            throw new \InvalidArgumentException('deriveShareToken requires jobId, accessToken, and secret.');
        }

        $rawHmac = hash_hmac('sha256', "share:{$jobId}:{$accessToken}", $secret, true);
        return self::base64UrlEncode($rawHmac);
    }

    /**
     * Constant-time comparison of two hex strings.
     */
    public static function safeEqualHex(?string $left, ?string $right): bool
    {
        if ($left === null || $right === null || $left === '' || $right === '') {
            return false;
        }

        if (strlen($left) !== strlen($right)) {
            return false;
        }

        return hash_equals($left, $right);
    }

    /**
     * Extract client IP from request, taking TRUST_PROXY into account.
     */
    public static function clientIp(mixed $request, bool|object $trustProxy = false): string
    {
        $shouldTrust = is_object($trustProxy) ? (bool) ($trustProxy->trustProxy ?? false) : (bool) $trustProxy;
        if ($shouldTrust) {
            $forwarded = null;
            if ($request instanceof Request) {
                $forwarded = $request->header('x-forwarded-for');
            } elseif (is_array($request)) {
                $headers = $request['headers'] ?? $request;
                $forwarded = $headers['x-forwarded-for'] ?? $headers['X-Forwarded-For'] ?? null;
            } elseif (is_object($request)) {
                if (isset($request->headers)) {
                    $headers = is_array($request->headers) ? $request->headers : (array) $request->headers;
                    $forwarded = $headers['x-forwarded-for'] ?? $headers['X-Forwarded-For'] ?? null;
                }
            }

            if (is_array($forwarded)) {
                $forwarded = $forwarded[0] ?? null;
            }

            if (is_string($forwarded) && $forwarded !== '') {
                $parts = explode(',', $forwarded);
                $first = trim($parts[0]);
                if ($first !== '') {
                    return $first;
                }
            }
        }

        if ($request instanceof Request) {
            return $request->server('REMOTE_ADDR') ?? $request->ip() ?? 'unknown';
        }

        if (is_array($request)) {
            if (isset($request['socket']['remoteAddress'])) {
                return (string) $request['socket']['remoteAddress'];
            }
            if (isset($request['remoteAddress'])) {
                return (string) $request['remoteAddress'];
            }
        }

        if (is_object($request)) {
            if (isset($request->socket->remoteAddress)) {
                return (string) $request->socket->remoteAddress;
            }
        }

        return 'unknown';
    }

    /**
     * Extract bearer token from Authorization header or accessToken query parameter.
     */
    public static function bearerToken(mixed $request, mixed $url = null): string
    {
        $header = '';
        if ($request instanceof Request) {
            $header = $request->header('authorization', '');
            if (str_starts_with($header, 'Bearer ')) {
                return trim(substr($header, 7));
            }
            return (string) $request->query('accessToken', '');
        }

        if (is_array($request)) {
            $headers = $request['headers'] ?? $request;
            $header = $headers['authorization'] ?? $headers['Authorization'] ?? '';
        } elseif (is_object($request) && isset($request->headers)) {
            $headers = is_array($request->headers) ? $request->headers : (array) $request->headers;
            $header = $headers['authorization'] ?? $headers['Authorization'] ?? '';
        }

        if (is_string($header) && str_starts_with($header, 'Bearer ')) {
            return trim(substr($header, 7));
        }

        if ($url instanceof \Psr\Http\Message\UriInterface || (is_object($url) && method_exists($url, '__toString'))) {
            parse_str(parse_url((string) $url, PHP_URL_QUERY) ?? '', $query);
            return (string) ($query['accessToken'] ?? '');
        }

        if (is_string($url)) {
            parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $query);
            return (string) ($query['accessToken'] ?? '');
        }

        return '';
    }
}
