<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\BridgeClient;
use Tests\TestCase;

class BridgeTest extends TestCase
{
    private BridgeClient $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = new BridgeClient();
    }

    public function test_check_url_allows_valid_public_url(): void
    {
        $verdict = $this->client->checkUrl('https://example.com/');
        $this->assertTrue($verdict['ok']);
        $this->assertNull($verdict['code']);
        $this->assertSame('https://example.com/', $verdict['url']);
        $this->assertSame('example.com', $verdict['hostname']);
        $this->assertSame('https:', $verdict['protocol']);
    }

    public function test_check_url_rejects_empty_url(): void
    {
        $verdict = $this->client->checkUrl('');
        $this->assertFalse($verdict['ok']);
        $this->assertSame('empty-url', $verdict['code']);
        $this->assertSame('URL kosong.', $verdict['message']);
    }

    public function test_check_url_rejects_ip_literal(): void
    {
        $verdict = $this->client->checkUrl('http://127.0.0.1/');
        $this->assertFalse($verdict['ok']);
        $this->assertSame('ip-literal-not-allowed', $verdict['code']);
        $this->assertSame('Alamat IP langsung tidak diterima. Gunakan nama domain.', $verdict['message']);
    }

    public function test_check_url_rejects_localhost(): void
    {
        $verdict = $this->client->checkUrl('http://localhost/test');
        $this->assertFalse($verdict['ok']);
        $this->assertSame('local-host-name', $verdict['code']);
        $this->assertSame('Nama localhost mengarah ke mesin lokal.', $verdict['message']);
    }

    public function test_check_url_rejects_non_http_protocol(): void
    {
        $verdict = $this->client->checkUrl('ftp://example.com/');
        $this->assertFalse($verdict['ok']);
        $this->assertSame('protocol-not-allowed', $verdict['code']);
        $this->assertSame('Hanya http dan https yang didukung, bukan ftp.', $verdict['message']);
    }
}
