<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\AksaraConfig;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class ConfigTest extends TestCase
{
    public function test_default_config_matches_node_defaults(): void
    {
        $config = new AksaraConfig([], [
            'NODE_ENV' => 'development',
        ]);

        $this->assertSame('development', $config->environment);
        $this->assertSame('127.0.0.1', $config->host);
        $this->assertSame(8787, $config->port);
        $this->assertSame(['http://localhost:3000'], $config->frontendOrigins);
        $this->assertFalse($config->trustProxy);
        $this->assertSame('aksaranetra-development-secret-change-me', $config->secret);
        $this->assertSame(1, $config->concurrency);
        $this->assertSame(180000, $config->timeoutMs);
        $this->assertSame(7 * AksaraConfig::DAY, $config->retentionMs);
        $this->assertSame(AksaraConfig::HOUR, $config->rateWindowMs);
        $this->assertSame(10, $config->rateLimit); // 10 in development
        $this->assertSame(8192, $config->maxBodyBytes);
        $this->assertSame(AksaraConfig::HOUR, $config->cleanupIntervalMs);
    }

    public function test_production_environment_sets_rate_limit_to_5(): void
    {
        $config = new AksaraConfig([], [
            'NODE_ENV' => 'production',
            'BACKEND_SECRET' => 'a-secret-that-is-at-least-32-characters-long!',
        ]);

        $this->assertSame('production', $config->environment);
        $this->assertSame(5, $config->rateLimit);
    }

    public function test_production_rejects_secret_shorter_than_32_characters(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('BACKEND_SECRET minimal 32 karakter di production.');

        new AksaraConfig([], [
            'NODE_ENV' => 'production',
            'BACKEND_SECRET' => 'short-secret',
        ]);
    }

    public function test_parses_comma_separated_origins(): void
    {
        $config = new AksaraConfig([], [
            'FRONTEND_ORIGINS' => 'http://localhost:3000, https://aksaranetra.test, http://127.0.0.1:3000 ',
        ]);

        $this->assertSame([
            'http://localhost:3000',
            'https://aksaranetra.test',
            'http://127.0.0.1:3000',
        ], $config->frontendOrigins);
    }

    public function test_validates_integer_ranges_with_indonesian_messages(): void
    {
        // Port out of range
        try {
            new AksaraConfig([], ['PORT' => '70000']);
            $this->fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('PORT harus berupa bilangan bulat 1–65535.', $e->getMessage());
        }

        // Timeout below minimum
        try {
            new AksaraConfig([], ['AUDIT_TIMEOUT_MS' => '10000']);
            $this->fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('AUDIT_TIMEOUT_MS harus berupa bilangan bulat 30000–600000.', $e->getMessage());
        }

        // Rate limit non-integer
        try {
            new AksaraConfig([], ['AUDIT_RATE_LIMIT' => 'invalid']);
            $this->fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('AUDIT_RATE_LIMIT harus berupa bilangan bulat 1–100.', $e->getMessage());
        }
    }

    public function test_trust_proxy_boolean_flag(): void
    {
        $configEnabled = new AksaraConfig([], ['TRUST_PROXY' => '1']);
        $this->assertTrue($configEnabled->trustProxy);

        $configDisabled = new AksaraConfig([], ['TRUST_PROXY' => '0']);
        $this->assertFalse($configDisabled->trustProxy);
    }
}
