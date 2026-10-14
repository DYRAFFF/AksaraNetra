<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\SecurityService;
use PHPUnit\Framework\TestCase;

class SecurityTest extends TestCase
{
    private const FIXED_SECRET = 'test-secret-12345678901234567890';

    /**
     * Test known-answer vectors generated directly from Node.js security.mjs
     */
    public function test_known_answer_vectors_match_node(): void
    {
        // 1. Empty string digest
        $this->assertSame(
            '602b4cb69081ecc4e796fff01bc12d841e962d2b74101f4726914b689deded37',
            SecurityService::digest('', self::FIXED_SECRET)
        );

        // 2. String value digest
        $this->assertSame(
            'c707803c6dcf06d3f1fdc72daa7ec72eeebc3efa12316d53bd4c9eff3826a371',
            SecurityService::digest('hello-world', self::FIXED_SECRET)
        );

        // 3. Numeric value digest
        $this->assertSame(
            'bf12bc000172b0dfb17c5f15b2552268367b1f155cdef226a93825e909289da9',
            SecurityService::digest(12345, self::FIXED_SECRET)
        );

        // 4. deriveShareToken known vector
        $shareToken = SecurityService::deriveShareToken(
            jobId: 'aud_123456789012345678901234',
            accessToken: 'token_abcdef123456',
            secret: self::FIXED_SECRET
        );
        $this->assertSame('nlKZI6QX5KVIC9PG6zmR0PB0ur2Fb09thpYn5pBCwC8', $shareToken);

        // Also test array argument syntax for deriveShareToken
        $shareTokenArray = SecurityService::deriveShareToken([
            'jobId' => 'aud_123456789012345678901234',
            'accessToken' => 'token_abcdef123456',
            'secret' => self::FIXED_SECRET,
        ]);
        $this->assertSame('nlKZI6QX5KVIC9PG6zmR0PB0ur2Fb09thpYn5pBCwC8', $shareTokenArray);
    }

    public function test_random_job_id_format(): void
    {
        $id1 = SecurityService::randomJobId();
        $id2 = SecurityService::randomJobId();

        $this->assertNotSame($id1, $id2);
        $this->assertStringStartsWith('aud_', $id1);
        $this->assertSame(28, strlen($id1)); // 'aud_' (4) + 18 bytes in base64url (24) = 28
        $this->assertMatchesRegularExpression('/^aud_[A-Za-z0-9_-]{24}$/', $id1);
    }

    public function test_random_token_format(): void
    {
        $token = SecurityService::randomToken(32);
        $this->assertSame(43, strlen($token)); // 32 bytes in base64url unpadded = 43 chars
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $token);

        $custom = SecurityService::randomToken(16);
        $this->assertSame(22, strlen($custom)); // 16 bytes in base64url unpadded = 22 chars
    }

    public function test_safe_equal_hex(): void
    {
        $this->assertTrue(SecurityService::safeEqualHex('abcdef', 'abcdef'));
        $this->assertFalse(SecurityService::safeEqualHex('abcdef', 'abcdeg'));
        $this->assertFalse(SecurityService::safeEqualHex('abcdef', 'abcd'));
        $this->assertFalse(SecurityService::safeEqualHex('', ''));
        $this->assertFalse(SecurityService::safeEqualHex(null, 'abcdef'));
        $this->assertFalse(SecurityService::safeEqualHex('abcdef', null));
    }

    public function test_client_ip_direct_and_trusted(): void
    {
        $directRequest = [
            'socket' => ['remoteAddress' => '192.168.1.1'],
            'headers' => ['x-forwarded-for' => '10.0.0.1, 10.0.0.2'],
        ];

        // Without trustProxy: ignores x-forwarded-for
        $this->assertSame('192.168.1.1', SecurityService::clientIp($directRequest, trustProxy: false));

        // With trustProxy: extracts first IP from x-forwarded-for
        $this->assertSame('10.0.0.1', SecurityService::clientIp($directRequest, trustProxy: true));

        // When x-forwarded-for is missing but trustProxy is true
        $noForwarded = [
            'socket' => ['remoteAddress' => '192.168.1.50'],
            'headers' => [],
        ];
        $this->assertSame('192.168.1.50', SecurityService::clientIp($noForwarded, trustProxy: true));

        // Empty request returns unknown
        $this->assertSame('unknown', SecurityService::clientIp([], trustProxy: false));
    }

    public function test_bearer_token_extraction(): void
    {
        // Bearer header takes precedence
        $reqWithHeader = [
            'headers' => ['authorization' => 'Bearer my-secret-token-123'],
        ];
        $this->assertSame('my-secret-token-123', SecurityService::bearerToken($reqWithHeader, 'http://localhost?accessToken=other'));

        // Query param fallback when header is missing
        $reqNoHeader = ['headers' => []];
        $this->assertSame('other', SecurityService::bearerToken($reqNoHeader, 'http://localhost?accessToken=other'));

        // Neither present
        $this->assertSame('', SecurityService::bearerToken($reqNoHeader, 'http://localhost'));
    }
}
