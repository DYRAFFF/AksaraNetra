<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

class CorsTest extends TestCase
{
    public function test_rejects_untrusted_browser_origins(): void
    {
        $response = $this->get('/health', [
            'Origin' => 'https://evil.test',
        ]);

        $response->assertStatus(403);
        $response->assertJson([
            'error' => 'origin-not-allowed',
            'message' => 'Origin frontend tidak diizinkan.',
        ]);
        $this->assertFalse($response->headers->has('access-control-allow-origin'));
    }

    public function test_allows_trusted_browser_origins(): void
    {
        $response = $this->get('/health', [
            'Origin' => 'http://localhost:3000',
        ]);

        $response->assertStatus(200);
        $response->assertHeader('access-control-allow-origin', 'http://localhost:3000');
        $response->assertHeader('vary', 'Origin');
        $response->assertHeader('access-control-allow-methods', 'GET, POST, PUT, DELETE, OPTIONS');
        $response->assertHeader('access-control-allow-headers', 'Authorization, Content-Type');
        $response->assertHeader('access-control-allow-credentials', 'true');
        $response->assertHeader('access-control-max-age', '600');
    }

    public function test_preflight_options_for_trusted_origin(): void
    {
        $response = $this->call('OPTIONS', '/health', [], [], [], [
            'HTTP_ORIGIN' => 'http://localhost:3000',
        ]);

        $response->assertStatus(204);
        $response->assertHeader('access-control-allow-origin', 'http://localhost:3000');
        $response->assertHeader('access-control-max-age', '600');
        $this->assertEmpty($response->getContent());
    }

    public function test_preflight_options_for_untrusted_origin_returns_403(): void
    {
        $response = $this->call('OPTIONS', '/health', [], [], [], [
            'HTTP_ORIGIN' => 'https://evil.test',
        ]);

        $response->assertStatus(403);
        $response->assertJson([
            'error' => 'origin-not-allowed',
            'message' => 'Origin frontend tidak diizinkan.',
        ]);
    }

    public function test_requests_without_origin_are_allowed(): void
    {
        $response = $this->get('/health');

        $response->assertStatus(200);
        $response->assertJson([
            'ok' => true,
            'worker' => 'ready',
        ]);
        $this->assertFalse($response->headers->has('access-control-allow-origin'));
    }
}
