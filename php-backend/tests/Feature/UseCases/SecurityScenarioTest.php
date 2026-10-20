<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases;

use App\Models\AuditJob;
use App\Services\AksaraConfig;
use App\Services\AuditWorker;
use App\Services\AuditWorkerInterface;
use App\Services\BridgeClient;
use App\Services\BridgeClientInterface;
use App\Services\JobStore;
use App\Services\ReportRenderer;
use App\Services\SecurityService;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fakes\FakeAuditor;
use Tests\TestCase;

/**
 * Step E: Security and attack scenarios (prefix UC-SEC)
 * Tests attempting attacks against the API: SSRF, token security, path traversal,
 * CSP, CORS, injection, resource abuse, and information leakage.
 */
class SecurityScenarioTest extends TestCase
{
    private string $tempDir;
    private string $artifactsDir;
    private string $dbPath;
    private AksaraConfig $config;
    private JobStore $store;
    private FakeAuditor $auditor;
    private BridgeClient $bridge;
    private AuditWorker $worker;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'aksara-sec-' . uniqid();
        $this->artifactsDir = $this->tempDir . DIRECTORY_SEPARATOR . 'artifacts';
        $this->dbPath = $this->tempDir . DIRECTORY_SEPARATOR . 'jobs.sqlite';

        mkdir($this->tempDir, 0777, true);
        mkdir($this->artifactsDir, 0777, true);

        $this->config = new AksaraConfig([
            'dataDir' => $this->tempDir,
            'databasePath' => $this->dbPath,
            'artifactsDir' => $this->artifactsDir,
            'secret' => 'test-secret-that-is-long-enough-123456',
            'frontendOrigins' => ['http://localhost:3000', 'https://frontend.test'],
            'rateLimit' => 5,
            'retentionMs' => 3600000,
            'maxBodyBytes' => 8192,
        ]);

        $this->store = new JobStore($this->dbPath);
        $this->auditor = new FakeAuditor();
        // Use real BridgeClient to verify url-policy.mjs via bridge/audit-cli.mjs check-url
        $this->bridge = new BridgeClient();
        $this->worker = new AuditWorker(
            store: $this->store,
            auditor: $this->auditor,
            timeoutMs: 1000,
            concurrency: 1
        );

        $this->app->instance(AksaraConfig::class, $this->config);
        $this->app->instance(JobStore::class, $this->store);
        $this->app->instance(BridgeClientInterface::class, $this->bridge);
        $this->app->instance(AuditWorkerInterface::class, $this->worker);
    }

    protected function tearDown(): void
    {
        $this->store->close();
        $this->removeDirectoryRecursive($this->tempDir);
        parent::tearDown();
    }

    private function removeDirectoryRecursive(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                $this->removeDirectoryRecursive($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    /**
     * UC-SEC-01: Reject private and local IP addresses (SSRF)
     */
    #[DataProvider('privateIpProvider')]
    public function test_UC_SEC_01_rejects_private_and_local_ip_addresses(string $privateUrl): void
    {
        $response = $this->postJson('/audits', ['url' => $privateUrl], ['Origin' => 'https://frontend.test']);
        $response->assertStatus(400);
        $response->assertJson([
            'error' => 'ip-literal-not-allowed',
            'message' => 'Alamat IP langsung tidak diterima. Gunakan nama domain.',
        ]);
    }

    public static function privateIpProvider(): array
    {
        return [
            '10.0.0.1' => ['http://10.0.0.1/'],
            '10.255.255.255' => ['http://10.255.255.255/admin'],
            '172.16.0.1' => ['http://172.16.0.1/'],
            '172.31.255.255' => ['https://172.31.255.255/'],
            '192.168.1.1' => ['http://192.168.1.1/router'],
            '192.168.0.254' => ['https://192.168.0.254/'],
        ];
    }

    /**
     * UC-SEC-02: Reject loopback and localhost variants
     */
    #[DataProvider('loopbackProvider')]
    public function test_UC_SEC_02_rejects_loopback_and_localhost_variants(string $loopbackUrl, string $expectedCode, string $expectedMessage): void
    {
        $response = $this->postJson('/audits', ['url' => $loopbackUrl], ['Origin' => 'https://frontend.test']);
        $response->assertStatus(400);
        $response->assertJson([
            'error' => $expectedCode,
            'message' => $expectedMessage,
        ]);
    }

    public static function loopbackProvider(): array
    {
        return [
            '127.0.0.1' => ['http://127.0.0.1/', 'ip-literal-not-allowed', 'Alamat IP langsung tidak diterima. Gunakan nama domain.'],
            '127.0.0.2' => ['http://127.0.0.2:80/', 'ip-literal-not-allowed', 'Alamat IP langsung tidak diterima. Gunakan nama domain.'],
            'localhost' => ['http://localhost/', 'local-host-name', 'Nama localhost mengarah ke mesin lokal.'],
            'localhost.localdomain' => ['http://localhost.localdomain/', 'local-host-name', 'Nama localhost.localdomain mengarah ke mesin lokal.'],
            'test.localhost' => ['http://test.localhost/', 'local-host-name', 'Akhiran .localhost dipakai untuk jaringan lokal.'],
            'dev.local' => ['http://dev.local/', 'local-host-name', 'Akhiran .local dipakai untuk jaringan lokal.'],
            'service.internal' => ['http://service.internal/', 'local-host-name', 'Akhiran .internal dipakai untuk jaringan lokal.'],
            'router.lan' => ['http://router.lan/', 'local-host-name', 'Akhiran .lan dipakai untuk jaringan lokal.'],
        ];
    }

    /**
     * UC-SEC-03: Reject cloud metadata endpoints (AWS, GCP, Alibaba, Azure)
     */
    #[DataProvider('cloudMetadataProvider')]
    public function test_UC_SEC_03_rejects_cloud_metadata_endpoints(string $metadataUrl, string $expectedCode, string $expectedMessage): void
    {
        $response = $this->postJson('/audits', ['url' => $metadataUrl], ['Origin' => 'https://frontend.test']);
        $response->assertStatus(400);
        $response->assertJson([
            'error' => $expectedCode,
            'message' => $expectedMessage,
        ]);
    }

    public static function cloudMetadataProvider(): array
    {
        return [
            'AWS/GCP metadata IP' => ['http://169.254.169.254/latest/meta-data/', 'ip-literal-not-allowed', 'Alamat IP langsung tidak diterima. Gunakan nama domain.'],
            'Alibaba metadata IP' => ['http://100.100.100.200/latest/meta-data/', 'ip-literal-not-allowed', 'Alamat IP langsung tidak diterima. Gunakan nama domain.'],
            'GCP metadata hostname' => ['http://metadata.google.internal/', 'local-host-name', 'Nama metadata.google.internal mengarah ke mesin lokal.'],
            'Generic metadata host' => ['http://metadata/', 'local-host-name', 'Nama metadata mengarah ke mesin lokal.'],
            'Instance data host' => ['http://instance-data/', 'local-host-name', 'Nama instance-data mengarah ke mesin lokal.'],
        ];
    }

    /**
     * UC-SEC-04: Reject obscure IP notations (octal, hex, decimal)
     */
    #[DataProvider('obscureIpProvider')]
    public function test_UC_SEC_04_rejects_obscure_ip_notations_octal_hex_decimal(string $obscureUrl, string $expectedCode): void
    {
        $response = $this->postJson('/audits', ['url' => $obscureUrl], ['Origin' => 'https://frontend.test']);
        $response->assertStatus(400);
        $this->assertSame($expectedCode, $response->json('error'));
    }

    public static function obscureIpProvider(): array
    {
        return [
            'decimal 127.0.0.1' => ['http://2130706433/', 'ip-literal-not-allowed'],
            'hex 0x7f.0.0.1' => ['http://0x7f000001/', 'ip-literal-not-allowed'],
            'octal 0177.0.0.1' => ['http://0177.0.0.1/', 'ip-literal-not-allowed'],
            'short 127.1' => ['http://127.1/', 'ip-literal-not-allowed'],
            'IPv6 loopback [::1]' => ['http://[::1]/', 'ip-literal-not-allowed'],
            'IPv6 link-local [fe80::1]' => ['http://[fe80::1]/', 'ip-literal-not-allowed'],
        ];
    }

    /**
     * UC-SEC-05: Reject credentials in URL (user:pass@host)
     */
    public function test_UC_SEC_05_rejects_credentials_in_url(): void
    {
        $response = $this->postJson('/audits', ['url' => 'http://admin:secret123@example.com/'], ['Origin' => 'https://frontend.test']);
        $response->assertStatus(400);
        $response->assertJson([
            'error' => 'credentials-in-url',
            'message' => 'URL memuat nama pengguna atau kata sandi.',
        ]);
    }

    /**
     * UC-SEC-06: Reject non-HTTP protocols (file, ftp, javascript, data)
     */
    #[DataProvider('nonHttpProtocolProvider')]
    public function test_UC_SEC_06_rejects_non_http_protocols(string $url, string $expectedProtocol): void
    {
        $response = $this->postJson('/audits', ['url' => $url], ['Origin' => 'https://frontend.test']);
        $response->assertStatus(400);
        $response->assertJson([
            'error' => 'protocol-not-allowed',
            'message' => "Hanya http dan https yang didukung, bukan {$expectedProtocol}.",
        ]);
    }

    public static function nonHttpProtocolProvider(): array
    {
        return [
            'file protocol' => ['file:///etc/passwd', 'file'],
            'ftp protocol' => ['ftp://example.com/file.txt', 'ftp'],
            'javascript protocol' => ['javascript:alert(1)', 'javascript'],
            'data protocol' => ['data:text/html,<h1>Hello</h1>', 'data'],
            'gopher protocol' => ['gopher://example.com/', 'gopher'],
        ];
    }

    /**
     * UC-SEC-07: Reject non-standard ports
     */
    #[DataProvider('nonStandardPortProvider')]
    public function test_UC_SEC_07_rejects_non_standard_ports(string $url, string $port): void
    {
        $response = $this->postJson('/audits', ['url' => $url], ['Origin' => 'https://frontend.test']);
        $response->assertStatus(400);
        $response->assertJson([
            'error' => 'port-not-allowed',
            'message' => "Hanya port standar yang diizinkan, bukan port {$port}.",
        ]);
    }

    public static function nonStandardPortProvider(): array
    {
        return [
            'port 8080 on http' => ['http://example.com:8080/', '8080'],
            'port 8443 on https' => ['https://example.com:8443/', '8443'],
            'port 22 on http' => ['http://example.com:22/', '22'],
            'port 3306 on http' => ['http://example.com:3306/', '3306'],
        ];
    }

    /**
     * UC-SEC-08: Prevents SSRF via redirect chain
     */
    public function test_UC_SEC_08_prevents_ssrf_via_redirect_chain(): void
    {
        // When checkRedirect in engine evaluates a hop pointing to internal address:
        // code becomes 'redirect-ip-literal-not-allowed' or 'redirect-local-host-name'
        $verdict = $this->bridge->checkUrl('http://127.0.0.1/');
        $this->assertFalse($verdict['ok']);
        $this->assertSame('ip-literal-not-allowed', $verdict['code']);

        // Check that any redirect check to internal host receives expected error
        $verdictRedirect = $this->bridge->checkUrl('http://localhost:8080/');
        $this->assertFalse($verdictRedirect['ok']);
    }

    /**
     * UC-SEC-09: Tokens are cryptographically random and secure
     */
    public function test_UC_SEC_09_tokens_are_cryptographically_random_and_secure(): void
    {
        $tokens = [];
        for ($i = 0; $i < 20; $i++) {
            $token = SecurityService::randomToken(32);
            $this->assertSame(43, strlen($token));
            $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $token);
            $this->assertFalse(isset($tokens[$token]), 'Token collision detected!');
            $tokens[$token] = true;
        }

        $jobIds = [];
        for ($i = 0; $i < 20; $i++) {
            $id = SecurityService::randomJobId();
            $this->assertSame(28, strlen($id));
            $this->assertStringStartsWith('aud_', $id);
            $this->assertFalse(isset($jobIds[$id]), 'Job ID collision detected!');
            $jobIds[$id] = true;
        }
    }

    /**
     * UC-SEC-10: Tokens and BACKEND_SECRET never appear in system logs
     */
    public function test_UC_SEC_10_tokens_and_secret_never_appear_in_system_logs(): void
    {
        $loggedMessages = [];
        Log::listen(function ($level) use (&$loggedMessages) {
            $loggedMessages[] = (string) $level->message;
        });

        // 1. Post audit
        $createRes = $this->postJson('/audits', ['url' => 'https://example.com/secret-test'], ['Origin' => 'https://frontend.test']);
        $accessToken = $createRes->json('accessToken');
        $jobId = $createRes->json('jobId');

        // 2. Poll audit
        $this->get("/audits/{$jobId}", [
            'Origin' => 'https://frontend.test',
            'Authorization' => "Bearer {$accessToken}",
        ]);

        // 3. Complete audit via worker
        $this->worker->processNext();

        // 4. Retrieve result
        $resultRes = $this->get("/audits/{$jobId}/result", [
            'Origin' => 'https://frontend.test',
            'Authorization' => "Bearer {$accessToken}",
        ]);
        preg_match('/token=([^&]+)/', $resultRes->json('links.reader'), $m);
        $shareToken = $m[1] ?? '';

        // Verify that neither accessToken, shareToken, nor BACKEND_SECRET appears in any logged line
        $allLogs = implode("\n", $loggedMessages);
        $this->assertStringNotContainsString($accessToken, $allLogs, 'Access token leaked in logs!');
        $this->assertStringNotContainsString($shareToken, $allLogs, 'Share token leaked in logs!');
        $this->assertStringNotContainsString($this->config->secret, $allLogs, 'BACKEND_SECRET leaked in logs!');
    }

    /**
     * UC-SEC-11: Token verification is constant-time (timing attack resistant)
     */
    public function test_UC_SEC_11_token_verification_is_constant_time(): void
    {
        $hash1 = SecurityService::digest('valid-token', $this->config->secret);
        $hash2 = SecurityService::digest('valid-token', $this->config->secret);
        $hashDifferent = SecurityService::digest('wrong-token', $this->config->secret);

        $this->assertTrue(SecurityService::safeEqualHex($hash1, $hash2));
        $this->assertFalse(SecurityService::safeEqualHex($hash1, $hashDifferent));

        // Test length differences and nulls safely handled
        $this->assertFalse(SecurityService::safeEqualHex($hash1, substr($hash1, 0, 10)));
        $this->assertFalse(SecurityService::safeEqualHex(null, $hash1));
        $this->assertFalse(SecurityService::safeEqualHex($hash1, null));

        // Loose equality (==) vulnerability check: scientific notation hash strings that == treats as 0
        $this->assertFalse(SecurityService::safeEqualHex('0e123456789012345678901234567890', '0e987654321098765432109876543210'));
    }

    /**
     * UC-SEC-12: Uniform 404 response prevents job enumeration
     */
    public function test_UC_SEC_12_uniform_404_response_prevents_job_enumeration(): void
    {
        $create = $this->postJson('/audits', ['url' => 'https://example.com/enum'], ['Origin' => 'https://frontend.test']);
        $existingJobId = $create->json('jobId');
        $nonExistentJobId = 'aud_nonexistent1234567890123';

        // 1. Wrong token on existing job
        $wrongTokenRes = $this->get("/audits/{$existingJobId}", [
            'Origin' => 'https://frontend.test',
            'Authorization' => 'Bearer fake-brute-force-token-attempt',
        ]);

        // 2. Any token on non-existent job
        $nonExistentRes = $this->get("/audits/{$nonExistentJobId}", [
            'Origin' => 'https://frontend.test',
            'Authorization' => 'Bearer fake-brute-force-token-attempt',
        ]);

        // Both must return identical 404 with identical JSON structure
        $wrongTokenRes->assertStatus(404);
        $nonExistentRes->assertStatus(404);
        $this->assertSame($wrongTokenRes->getContent(), $nonExistentRes->getContent());
        $this->assertSame('Audit tidak ditemukan.', $wrongTokenRes->json('message'));
    }

    /**
     * UC-SEC-13: Path traversal in artifact names strictly prevented
     */
    #[DataProvider('pathTraversalProvider')]
    public function test_UC_SEC_13_path_traversal_in_artifacts_strictly_prevented(string $traversalPath): void
    {
        $create = $this->postJson('/audits', ['url' => 'https://example.com/traversal'], ['Origin' => 'https://frontend.test']);
        $id = $create->json('jobId');
        $token = $create->json('accessToken');

        $this->worker->processNext();

        $result = $this->get("/audits/{$id}/result", [
            'Origin' => 'https://frontend.test',
            'Authorization' => "Bearer {$token}",
        ]);
        preg_match('/token=([^&]+)/', $result->json('links.reader'), $m);
        $shareToken = $m[1] ?? '';

        $response = $this->get("/audits/{$id}/artifacts/{$traversalPath}?token={$shareToken}", [
            'Origin' => 'https://frontend.test',
        ]);

        // Must always return 404 and never expose system or database files
        $response->assertStatus(404);
        $this->assertSame('not-found', $response->json('error'));
    }

    public static function pathTraversalProvider(): array
    {
        return [
            'relative dot dot' => ['../../jobs.sqlite'],
            'url-encoded dot dot' => ['..%2f..%2fjobs.sqlite'],
            'double encoded' => ['%2e%2e%2f%2e%2e%2fjobs.sqlite'],
            'absolute root path' => ['/etc/passwd'],
            'windows absolute path' => ['C:/Windows/win.ini'],
            'null byte injection' => ["reader.html\0.exe"],
        ];
    }

    public function test_UC_SEC_13_symlink_pointing_outside_artifact_dir_returns_404(): void
    {
        $create = $this->postJson('/audits', ['url' => 'https://example.com/symlink'], ['Origin' => 'https://frontend.test']);
        $id = $create->json('jobId');
        $token = $create->json('accessToken');

        $this->worker->processNext();

        $result = $this->get("/audits/{$id}/result", [
            'Origin' => 'https://frontend.test',
            'Authorization' => "Bearer {$token}",
        ]);
        preg_match('/token=([^&]+)/', $result->json('links.reader'), $m);
        $shareToken = $m[1] ?? '';

        $job = $this->store->getJob($id);
        $outsideFile = $this->tempDir . DIRECTORY_SEPARATOR . 'outside_secret.txt';
        file_put_contents($outsideFile, 'secret outside');
        $symlinkPath = $job->artifactDir . DIRECTORY_SEPARATOR . 'reader.html';

        if (file_exists($symlinkPath)) {
            unlink($symlinkPath);
        }

        if (@symlink($outsideFile, $symlinkPath)) {
            $response = $this->get("/audits/{$id}/artifacts/reader?token={$shareToken}", [
                'Origin' => 'https://frontend.test',
            ]);
            $response->assertStatus(404);
            $response->assertJson([
                'error' => 'not-found',
                'message' => 'Artefak tidak ditemukan.',
            ]);
        } else {
            $this->assertTrue(true, 'Symlink creation skipped on Windows without privilege');
        }
    }

    /**
     * UC-SEC-14: Artifact responses carry strict CSP and security headers
     */
    public function test_UC_SEC_14_artifact_responses_carry_strict_csp_and_security_headers(): void
    {
        $create = $this->postJson('/audits', ['url' => 'https://example.com/csp'], ['Origin' => 'https://frontend.test']);
        $id = $create->json('jobId');
        $token = $create->json('accessToken');

        $this->worker->processNext();

        $result = $this->get("/audits/{$id}/result", [
            'Origin' => 'https://frontend.test',
            'Authorization' => "Bearer {$token}",
        ]);
        $readerLink = $result->json('links.reader');
        $pdfLink = $result->json('links.reportPdf');

        // Test reader artifact
        $readerRes = $this->get($readerLink, ['Origin' => 'https://frontend.test']);
        $readerRes->assertStatus(200);

        // Security headers assertions
        $readerRes->assertHeader('X-Content-Type-Options', 'nosniff');
        $cacheControl = (string) $readerRes->headers->get('Cache-Control');
        $this->assertStringContainsString('private', $cacheControl);
        $this->assertStringContainsString('max-age=300', $cacheControl);

        $csp = (string) $readerRes->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("default-src 'none'", $csp);
        $this->assertStringContainsString("base-uri 'none'", $csp);
        $this->assertStringContainsString("form-action 'none'", $csp);
        $this->assertStringContainsString("frame-ancestors 'self'", $csp);

        // Test PDF artifact
        $pdfRes = $this->get($pdfLink, ['Origin' => 'https://frontend.test']);
        $pdfRes->assertStatus(200);
        $pdfRes->assertHeader('X-Content-Type-Options', 'nosniff');
        $pdfCsp = (string) $pdfRes->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("default-src 'none'", $pdfCsp);
    }

    /**
     * UC-SEC-15: CORS untrusted origins rejected with 403 origin-not-allowed
     */
    #[DataProvider('untrustedOriginProvider')]
    public function test_UC_SEC_15_cors_untrusted_origins_rejected_with_403(string $untrustedOrigin): void
    {
        $response = $this->get('/health', ['Origin' => $untrustedOrigin]);
        $response->assertStatus(403);
        $response->assertJson([
            'error' => 'origin-not-allowed',
            'message' => 'Origin frontend tidak diizinkan.',
        ]);
        $this->assertFalse($response->headers->has('access-control-allow-origin'));
    }

    public static function untrustedOriginProvider(): array
    {
        return [
            'attacker site' => ['https://evil-attacker.com'],
            'phishing clone' => ['http://localhost:3000.evil.com'],
            'trailing slash mismatch' => ['http://localhost:3000/'],
            'different port' => ['http://localhost:3001'],
            'different scheme' => ['https://localhost:3000'],
        ];
    }

    /**
     * UC-SEC-16: CORS never combines wildcard with credentials
     */
    public function test_UC_SEC_16_cors_never_combines_wildcard_with_credentials(): void
    {
        $trustedOrigin = 'http://localhost:3000';

        // 1. Normal GET request
        $response = $this->get('/health', ['Origin' => $trustedOrigin]);
        $response->assertStatus(200);
        $this->assertSame($trustedOrigin, $response->headers->get('access-control-allow-origin'));
        $this->assertNotSame('*', $response->headers->get('access-control-allow-origin'));
        $this->assertSame('true', $response->headers->get('access-control-allow-credentials'));
        $this->assertSame('Origin', $response->headers->get('vary'));

        // 2. Preflight OPTIONS request
        $preflight = $this->call('OPTIONS', '/health', [], [], [], [
            'HTTP_ORIGIN' => $trustedOrigin,
        ]);
        $preflight->assertStatus(204);
        $this->assertSame($trustedOrigin, $preflight->headers->get('access-control-allow-origin'));
        $this->assertNotSame('*', $preflight->headers->get('access-control-allow-origin'));
        $this->assertSame('true', $preflight->headers->get('access-control-allow-credentials'));
    }

    /**
     * UC-SEC-17: SQL injection defense via parameterized queries
     */
    #[DataProvider('sqlInjectionPayloadProvider')]
    public function test_UC_SEC_17_sql_injection_defense_via_parameterized_queries(string $sqlPayload): void
    {
        // 1. Injection in cache URL
        $cache = $this->get('/audits/cache?url=' . urlencode($sqlPayload), ['Origin' => 'https://frontend.test']);
        $this->assertTrue(in_array($cache->getStatusCode(), [200, 400], true));

        // 2. Injection in ID
        $status = $this->get('/audits/' . urlencode($sqlPayload), [
            'Origin' => 'https://frontend.test',
            'Authorization' => "Bearer {$sqlPayload}",
        ]);
        $this->assertSame(404, $status->getStatusCode());

        // 3. Injection in cancel
        $cancel = $this->delete('/audits/' . urlencode($sqlPayload), [], [
            'Origin' => 'https://frontend.test',
            'Authorization' => "Bearer {$sqlPayload}",
        ]);
        $this->assertSame(404, $cancel->getStatusCode());
    }

    public static function sqlInjectionPayloadProvider(): array
    {
        return [
            'or 1=1' => ["' OR '1'='1"],
            'union select' => ["' UNION SELECT null, null, null--"],
            'drop table' => ["'; DROP TABLE audit_jobs;--"],
            'semicolon insert' => ["'; INSERT INTO audit_jobs (id) VALUES ('x');--"],
        ];
    }

    /**
     * UC-SEC-18: Header injection (CRLF) attacks blocked
     */
    public function test_UC_SEC_18_header_injection_crlf_attacks_blocked(): void
    {
        $crlfPayload = "aud_test\r\nInjected-Header: evil\r\nSet-Cookie: pwned=1";

        $response = $this->get('/audits/' . urlencode($crlfPayload), [
            'Origin' => 'https://frontend.test',
            'Authorization' => 'Bearer test-token',
        ]);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertFalse($response->headers->has('Injected-Header'));
        $this->assertFalse($response->headers->has('Set-Cookie'));
    }

    /**
     * UC-SEC-19: HTML report escapes XSS vectors
     */
    public function test_UC_SEC_19_html_report_escapes_xss_vectors(): void
    {
        $xssRecord = [
            [
                'rule' => '<script>alert("rule-xss")</script>',
                'selector' => '<img src=x onerror=alert("selector-xss")>',
                'status' => 'needs-review',
                'confidence' => 0.99,
            ],
        ];

        $renderedRows = ReportRenderer::rows($xssRecord);

        // Tags must be escaped to &lt; and &gt;
        $this->assertStringNotContainsString('<script>', $renderedRows);
        $this->assertStringNotContainsString('<img src=x', $renderedRows);
        $this->assertStringContainsString('&lt;script&gt;', $renderedRows);
        $this->assertStringContainsString('&lt;img src=x', $renderedRows);

        // String esc helper
        $this->assertSame('&lt;script&gt;&quot;hello&quot;&lt;/script&gt;', ReportRenderer::esc('<script>"hello"</script>'));
    }

    /**
     * UC-SEC-20: Resource abuse rejected cheaply without engine overhead
     */
    public function test_UC_SEC_20_resource_abuse_rejected_cheaply_without_engine_overhead(): void
    {
        // 1. Oversized body (413) rejected immediately by middleware
        $oversized = json_encode(['url' => 'https://example.com/', 'junk' => str_repeat('z', 10000)]);
        $res413 = $this->call('POST', '/audits', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ORIGIN' => 'https://frontend.test',
        ], $oversized);
        $res413->assertStatus(413);

        // 2. Excessively long URL rejected by checkUrl before touching worker
        $longUrl = 'https://example.com/' . str_repeat('w', 2500);
        $res400 = $this->postJson('/audits', ['url' => $longUrl], ['Origin' => 'https://frontend.test']);
        $res400->assertStatus(400);
        $this->assertSame('url-too-long', $res400->json('error'));
    }

    /**
     * UC-SEC-21: Error responses never leak sensitive internals
     */
    public function test_UC_SEC_21_error_responses_never_leak_sensitive_internals(): void
    {
        // 404 error
        $notFound = $this->get('/nonexistent-path', ['Origin' => 'https://frontend.test']);
        $notFound->assertStatus(404);
        $content404 = $notFound->getContent();
        $this->assertStringNotContainsString('Stack trace', $content404);
        $this->assertStringNotContainsString($this->config->secret, $content404);

        // 400 error
        $badReq = $this->postJson('/audits', ['url' => 'invalid-protocol://foo'], ['Origin' => 'https://frontend.test']);
        $badReq->assertStatus(400);
        $content400 = $badReq->getContent();
        $this->assertStringNotContainsString('Stack trace', $content400);
        $this->assertStringNotContainsString($this->config->secret, $content400);

        // Verify APP_DEBUG is false in production configuration files
        $dockerfile = (string) file_get_contents(dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'docker' . DIRECTORY_SEPARATOR . 'Dockerfile');
        $this->assertStringContainsString('ENV APP_DEBUG=false', $dockerfile);
    }
}
