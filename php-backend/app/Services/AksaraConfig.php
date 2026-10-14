<?php

declare(strict_types=1);

namespace App\Services;

class AksaraConfig
{
    public const HOUR = 3600000;
    public const DAY = 86400000; // 24 * HOUR

    public readonly string $environment;
    public readonly string $host;
    public readonly int $port;
    public readonly string $dataDir;
    public readonly string $databasePath;
    public readonly string $artifactsDir;
    /** @var list<string> */
    public readonly array $frontendOrigins;
    public readonly bool $trustProxy;
    public readonly string $secret;
    public readonly int $concurrency;
    public readonly int $timeoutMs;
    public readonly int $retentionMs;
    public readonly int $rateWindowMs;
    public readonly int $rateLimit;
    public readonly int $maxBodyBytes;
    public readonly int $cleanupIntervalMs;

    public function __construct(array $overrides = [], ?array $env = null)
    {
        $getEnv = function (string $key) use ($env): ?string {
            if ($env !== null) {
                return array_key_exists($key, $env) ? (string) $env[$key] : null;
            }
            $val = getenv($key);
            if ($val !== false) {
                return (string) $val;
            }
            if (isset($_ENV[$key])) {
                return (string) $_ENV[$key];
            }
            if (isset($_SERVER[$key])) {
                return (string) $_SERVER[$key];
            }
            return null;
        };

        $environment = $overrides['environment']
            ?? $getEnv('NODE_ENV')
            ?? $getEnv('APP_ENV')
            ?? 'development';

        $integer = function (string $name, int $fallback, int $min = 1, int $max = PHP_INT_MAX) use ($overrides, $getEnv): int {
            if (array_key_exists($name, $overrides)) {
                $val = $overrides[$name];
            } else {
                $raw = $getEnv($name);
                $val = $raw !== null ? $raw : $fallback;
            }

            if (!is_numeric($val) || (int) $val != $val || str_contains((string) $val, '.')) {
                throw new \InvalidArgumentException("{$name} harus berupa bilangan bulat {$min}–{$max}.");
            }

            $num = (int) $val;
            if ($num < $min || $num > $max) {
                throw new \InvalidArgumentException("{$name} harus berupa bilangan bulat {$min}–{$max}.");
            }

            return $num;
        };

        $requiredSecret = function () use ($overrides, $environment, $getEnv): string {
            if (isset($overrides['secret'])) {
                $val = $overrides['secret'];
            } else {
                $val = $getEnv('BACKEND_SECRET') ?? '';
            }

            if ($environment === 'production' && strlen($val) < 32) {
                throw new \InvalidArgumentException('BACKEND_SECRET minimal 32 karakter di production.');
            }

            return $val !== '' ? $val : 'aksaranetra-development-secret-change-me';
        };

        $rawOrigins = $overrides['frontendOrigins']
            ?? array_values(array_filter(array_map('trim', explode(',', $getEnv('FRONTEND_ORIGINS') ?? 'http://localhost:3000'))));

        $dataDirRaw = $overrides['dataDir'] ?? $getEnv('BACKEND_DATA_DIR') ?? 'backend/data';
        $dataDir = $overrides['dataDir'] ?? (
            str_starts_with($dataDirRaw, '/') || (strlen($dataDirRaw) > 2 && $dataDirRaw[1] === ':')
                ? $dataDirRaw
                : (function_exists('app') && app() instanceof \Illuminate\Foundation\Application
                    ? base_path($dataDirRaw)
                    : $dataDirRaw)
        );

        $this->environment = $environment;
        $this->host = $overrides['host'] ?? $getEnv('HOST') ?? '127.0.0.1';
        $this->port = $integer('PORT', 8787, 1, 65535);
        $this->dataDir = $dataDir;
        $this->databasePath = $overrides['databasePath'] ?? rtrim($dataDir, '/\\') . DIRECTORY_SEPARATOR . 'jobs.sqlite';
        $this->artifactsDir = $overrides['artifactsDir'] ?? rtrim($dataDir, '/\\') . DIRECTORY_SEPARATOR . 'artifacts';
        $this->frontendOrigins = is_array($rawOrigins) ? $rawOrigins : [$rawOrigins];
        $this->trustProxy = $overrides['trustProxy'] ?? ($getEnv('TRUST_PROXY') === '1');
        $this->secret = $requiredSecret();
        $this->concurrency = 1;
        $this->timeoutMs = $integer('AUDIT_TIMEOUT_MS', 180000, 30000, 600000);
        $this->retentionMs = $integer('RESULT_RETENTION_MS', 7 * self::DAY, self::HOUR, 30 * self::DAY);
        $this->rateWindowMs = self::HOUR;
        $this->rateLimit = $integer(
            'AUDIT_RATE_LIMIT',
            $environment === 'production' ? 5 : 10,
            1,
            100
        );
        $this->maxBodyBytes = $integer('MAX_BODY_BYTES', 8192, 1024, 65536);
        $this->cleanupIntervalMs = $integer('CLEANUP_INTERVAL_MS', self::HOUR, 60000, self::DAY);
    }

    public static function loadConfig(array $overrides = [], ?array $env = null): self
    {
        return new self($overrides, $env);
    }

    public function toArray(): array
    {
        return [
            'environment' => $this->environment,
            'host' => $this->host,
            'port' => $this->port,
            'dataDir' => $this->dataDir,
            'databasePath' => $this->databasePath,
            'artifactsDir' => $this->artifactsDir,
            'frontendOrigins' => $this->frontendOrigins,
            'trustProxy' => $this->trustProxy,
            'secret' => $this->secret,
            'concurrency' => $this->concurrency,
            'timeoutMs' => $this->timeoutMs,
            'retentionMs' => $this->retentionMs,
            'rateWindowMs' => $this->rateWindowMs,
            'rateLimit' => $this->rateLimit,
            'maxBodyBytes' => $this->maxBodyBytes,
            'cleanupIntervalMs' => $this->cleanupIntervalMs,
        ];
    }
}
