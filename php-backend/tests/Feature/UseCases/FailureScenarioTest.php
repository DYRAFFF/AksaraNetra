<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases;

use App\Exceptions\AuditFailedException;
use App\Models\AuditJob;
use App\Services\AksaraConfig;
use App\Services\Auditor;
use App\Services\AuditWorker;
use App\Services\AuditWorkerInterface;
use App\Services\BridgeClientInterface;
use App\Services\JobStore;
use App\Services\ReportRenderer;
use App\Services\SecurityService;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fakes\FakeAuditor;
use Tests\Fakes\FakeBridgeClient;
use Tests\TestCase;

/**
 * Step D: Failure and abnormal cases (prefix UC-FAIL)
 * Table-driven tests validating error codes, status codes, and exact Indonesian messages.
 */
class FailureScenarioTest extends TestCase
{
    private string $tempDir;
    private string $artifactsDir;
    private string $dbPath;
    private AksaraConfig $config;
    private JobStore $store;
    private FakeAuditor $auditor;
    private FakeBridgeClient $bridge;
    private AuditWorker $worker;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'aksara-fail-' . uniqid();
        $this->artifactsDir = $this->tempDir . DIRECTORY_SEPARATOR . 'artifacts';
        $this->dbPath = $this->tempDir . DIRECTORY_SEPARATOR . 'jobs.sqlite';

        mkdir($this->tempDir, 0777, true);
        mkdir($this->artifactsDir, 0777, true);

        $this->config = new AksaraConfig([
            'dataDir' => $this->tempDir,
            'databasePath' => $this->dbPath,
            'artifactsDir' => $this->artifactsDir,
            'secret' => 'test-secret-that-is-long-enough-123456',
            'frontendOrigins' => ['https://frontend.test'],
            'rateLimit' => 3,
            'retentionMs' => 3600000,
            'maxBodyBytes' => 4096,
        ]);

        $this->store = new JobStore($this->dbPath);
        $this->auditor = new FakeAuditor();
        $this->bridge = new FakeBridgeClient();
        $this->worker = new AuditWorker(
            store: $this->store,
            auditor: $this->auditor,
            timeoutMs: 200,
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
     * UC-FAIL-01: missing url
     */
    public function test_UC_FAIL_01_missing_or_empty_url(): void
    {
        $response = $this->postJson('/audits', [], ['Origin' => 'https://frontend.test']);
        $response->assertStatus(400);
        $response->assertJson([
            'error' => 'empty-url',
            'message' => 'URL kosong.',
        ]);

        $emptyResponse = $this->postJson('/audits', ['url' => '   '], ['Origin' => 'https://frontend.test']);
        $emptyResponse->assertStatus(400);
        $emptyResponse->assertJson([
            'error' => 'empty-url',
            'message' => 'URL kosong.',
        ]);
    }

    /**
     * UC-FAIL-02: url not a string (integer, array, boolean)
     */
    #[DataProvider('nonStringUrlProvider')]
    public function test_UC_FAIL_02_url_not_a_string(mixed $nonStringUrl): void
    {
        $response = $this->postJson('/audits', ['url' => $nonStringUrl], ['Origin' => 'https://frontend.test']);
        $response->assertStatus(400);
        $this->assertSame('empty-url', $response->json('error'));
        $this->assertSame('URL kosong.', $response->json('message'));
    }

    public static function nonStringUrlProvider(): array
    {
        return [
            'integer url' => [12345],
            'array url' => [['https://example.com/']],
            'null url' => [null],
            'boolean url' => [true],
        ];
    }

    /**
     * UC-FAIL-03: url exceeds maximum length (2048 chars)
     */
    public function test_UC_FAIL_03_url_exceeds_maximum_length(): void
    {
        $longUrl = 'https://example.com/' . str_repeat('a', 2040);
        $this->assertGreaterThan(2048, strlen($longUrl));

        $this->bridge->setVerdict($longUrl, [
            'ok' => false,
            'code' => 'url-too-long',
            'message' => 'URL lebih panjang dari 2048 karakter.',
        ]);

        $response = $this->postJson('/audits', ['url' => $longUrl], ['Origin' => 'https://frontend.test']);
        $response->assertStatus(400);
        $response->assertJson([
            'error' => 'url-too-long',
            'message' => 'URL lebih panjang dari 2048 karakter.',
        ]);
    }

    /**
     * UC-FAIL-04: malformed JSON body
     */
    public function test_UC_FAIL_04_malformed_json_body(): void
    {
        $response = $this->call('POST', '/audits', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ORIGIN' => 'https://frontend.test',
        ], '{"url": "https://example.com", "unfinished": ');

        $response->assertStatus(400);
        $response->assertJson([
            'error' => 'bad-request',
            'message' => 'JSON tidak valid.',
        ]);
    }

    /**
     * UC-FAIL-05: empty request body
     */
    public function test_UC_FAIL_05_empty_request_body(): void
    {
        $response = $this->call('POST', '/audits', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ORIGIN' => 'https://frontend.test',
        ], '');

        $response->assertStatus(400);
        $response->assertJson([
            'error' => 'empty-url',
            'message' => 'URL kosong.',
        ]);
    }

    /**
     * UC-FAIL-06: unsupported media type (content-type not application/json)
     */
    #[DataProvider('unsupportedContentTypeProvider')]
    public function test_UC_FAIL_06_unsupported_media_type_content_type(string $contentType): void
    {
        $response = $this->call('POST', '/audits', [], [], [], [
            'CONTENT_TYPE' => $contentType,
            'HTTP_ORIGIN' => 'https://frontend.test',
        ], 'url=https%3A%2F%2Fexample.com');

        $response->assertStatus(415);
        $response->assertJson([
            'error' => 'bad-request',
            'message' => 'Gunakan Content-Type application/json.',
        ]);
    }

    public static function unsupportedContentTypeProvider(): array
    {
        return [
            'text/plain' => ['text/plain'],
            'application/x-www-form-urlencoded' => ['application/x-www-form-urlencoded'],
            'multipart/form-data' => ['multipart/form-data'],
            'application/xml' => ['application/xml'],
        ];
    }

    /**
     * UC-FAIL-07: request body exceeds MAX_BODY_BYTES (413)
     */
    public function test_UC_FAIL_07_request_body_exceeds_maximum_bytes(): void
    {
        $oversizedBody = json_encode([
            'url' => 'https://example.com/',
            'bloat' => str_repeat('X', 5000), // > 4096 config limit
        ]);

        $response = $this->call('POST', '/audits', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ORIGIN' => 'https://frontend.test',
        ], $oversizedBody);

        $response->assertStatus(413);
        $response->assertJson([
            'error' => 'bad-request',
            'message' => 'Body permintaan terlalu besar.',
        ]);

        // Content-Length header itself declared larger than maxBodyBytes
        $headerTooLarge = $this->call('POST', '/audits', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ORIGIN' => 'https://frontend.test',
            'HTTP_CONTENT_LENGTH' => '99999',
        ], '{"url":"https://example.com"}');
        $headerTooLarge->assertStatus(413);
        $headerTooLarge->assertJson([
            'error' => 'bad-request',
            'message' => 'Body permintaan terlalu besar.',
        ]);
    }

    /**
     * UC-FAIL-08: reuseExisting non-boolean values safely handled
     */
    #[DataProvider('nonBooleanReuseExistingProvider')]
    public function test_UC_FAIL_08_reuse_existing_non_boolean_handling(mixed $invalidValue): void
    {
        // Pre-create completed job to see if non-boolean reuseExisting triggers reuse
        $first = $this->postJson('/audits', ['url' => 'https://example.com/'], ['Origin' => 'https://frontend.test']);
        $this->worker->processNext();

        $second = $this->postJson('/audits', [
            'url' => 'https://example.com/',
            'reuseExisting' => $invalidValue,
        ], ['Origin' => 'https://frontend.test']);

        // Must NOT reuse cache; must return 202 queued as a fresh job
        $second->assertStatus(202);
        $this->assertSame('queued', $second->json('status'));
        $this->assertNotSame($first->json('jobId'), $second->json('jobId'));
    }

    public static function nonBooleanReuseExistingProvider(): array
    {
        return [
            'string true' => ['true'],
            'string 1' => ['1'],
            'integer 1' => [1],
            'array empty' => [[]],
            'string yes' => ['yes'],
        ];
    }

    /**
     * UC-FAIL-09: extra unknown fields safely ignored without breaking
     */
    public function test_UC_FAIL_09_unknown_payload_fields_ignored_safely(): void
    {
        $response = $this->postJson('/audits', [
            'url' => 'https://example.com/',
            'extra_admin_flag' => true,
            'unexpected_data' => ['nested' => 123],
            'hacker_probe' => '__proto__',
        ], ['Origin' => 'https://frontend.test']);

        $response->assertStatus(202);
        $this->assertMatchesRegularExpression('/^aud_/', $response->json('jobId'));
    }

    /**
     * UC-FAIL-10: missing authorization token
     */
    public function test_UC_FAIL_10_missing_authorization_token(): void
    {
        $create = $this->postJson('/audits', ['url' => 'https://example.com/'], ['Origin' => 'https://frontend.test']);
        $jobId = $create->json('jobId');

        // GET /audits/{id} without token
        $statusNoToken = $this->get("/audits/{$jobId}", ['Origin' => 'https://frontend.test']);
        $statusNoToken->assertStatus(404);
        $statusNoToken->assertJson(['error' => 'not-found', 'message' => 'Audit tidak ditemukan.']);

        // GET /audits/{id}/result without token
        $resultNoToken = $this->get("/audits/{$jobId}/result", ['Origin' => 'https://frontend.test']);
        $resultNoToken->assertStatus(404);
        $resultNoToken->assertJson(['error' => 'not-found', 'message' => 'Audit tidak ditemukan.']);

        // DELETE /audits/{id} without token
        $deleteNoToken = $this->delete("/audits/{$jobId}", [], ['Origin' => 'https://frontend.test']);
        $deleteNoToken->assertStatus(404);
        $deleteNoToken->assertJson(['error' => 'not-found', 'message' => 'Audit tidak ditemukan.']);
    }

    /**
     * UC-FAIL-11: invalid or mismatched token of another job
     */
    public function test_UC_FAIL_11_invalid_or_mismatched_authorization_token(): void
    {
        $job1 = $this->postJson('/audits', ['url' => 'https://example.com/1'], ['Origin' => 'https://frontend.test']);
        $job2 = $this->postJson('/audits', ['url' => 'https://example.com/2'], ['Origin' => 'https://frontend.test']);

        $id1 = $job1->json('jobId');
        $id2 = $job2->json('jobId');
        $token2 = $job2->json('accessToken');

        // Accessing Job 1 using Job 2's token returns 404
        $crossJob = $this->get("/audits/{$id1}", [
            'Origin' => 'https://frontend.test',
            'Authorization' => "Bearer {$token2}",
        ]);
        $crossJob->assertStatus(404);
        $crossJob->assertJson(['error' => 'not-found', 'message' => 'Audit tidak ditemukan.']);

        // Accessing with malformed token
        $malformed = $this->get("/audits/{$id1}", [
            'Origin' => 'https://frontend.test',
            'Authorization' => 'Bearer not-even-close-to-a-valid-token',
        ]);
        $malformed->assertStatus(404);
        $malformed->assertJson(['error' => 'not-found', 'message' => 'Audit tidak ditemukan.']);
    }

    /**
     * UC-FAIL-12: share token used where access token is required
     */
    public function test_UC_FAIL_12_share_token_rejected_on_private_endpoints(): void
    {
        $create = $this->postJson('/audits', ['url' => 'https://example.com/'], ['Origin' => 'https://frontend.test']);
        $id = $create->json('jobId');
        $accessToken = $create->json('accessToken');

        $this->worker->processNext();

        $resultRes = $this->get("/audits/{$id}/result", [
            'Origin' => 'https://frontend.test',
            'Authorization' => "Bearer {$accessToken}",
        ]);
        preg_match('/token=([^&]+)/', $resultRes->json('links.reader'), $m);
        $shareToken = $m[1] ?? '';
        $this->assertNotEmpty($shareToken);

        // Share token used on GET /audits/{id}
        $statusWithShare = $this->get("/audits/{$id}", [
            'Origin' => 'https://frontend.test',
            'Authorization' => "Bearer {$shareToken}",
        ]);
        $statusWithShare->assertStatus(404);
        $statusWithShare->assertJson(['error' => 'not-found', 'message' => 'Audit tidak ditemukan.']);

        // Share token used on GET /audits/{id}/result
        $resultWithShare = $this->get("/audits/{$id}/result", [
            'Origin' => 'https://frontend.test',
            'Authorization' => "Bearer {$shareToken}",
        ]);
        $resultWithShare->assertStatus(404);
        $resultWithShare->assertJson(['error' => 'not-found', 'message' => 'Audit tidak ditemukan.']);
    }

    /**
     * UC-FAIL-13: access token used on artifact endpoint (requires shareToken)
     */
    public function test_UC_FAIL_13_access_token_rejected_on_artifact_download(): void
    {
        $create = $this->postJson('/audits', ['url' => 'https://example.com/'], ['Origin' => 'https://frontend.test']);
        $id = $create->json('jobId');
        $accessToken = $create->json('accessToken');

        $this->worker->processNext();

        // 1. Passing accessToken via Bearer header
        $headerAccess = $this->get("/audits/{$id}/artifacts/reader", [
            'Origin' => 'https://frontend.test',
            'Authorization' => "Bearer {$accessToken}",
        ]);
        $headerAccess->assertStatus(404);
        $headerAccess->assertJson(['error' => 'not-found', 'message' => 'Artefak tidak ditemukan.']);

        // 2. Passing accessToken via ?token= query param (hash will not match shareTokenHash)
        $queryAccess = $this->get("/audits/{$id}/artifacts/reader?token={$accessToken}", [
            'Origin' => 'https://frontend.test',
        ]);
        $queryAccess->assertStatus(404);
        $queryAccess->assertJson(['error' => 'not-found', 'message' => 'Artefak tidak ditemukan.']);
    }

    /**
     * UC-FAIL-14: token in header versus ?accessToken= query param
     */
    public function test_UC_FAIL_14_token_via_header_versus_query_parameter(): void
    {
        $create = $this->postJson('/audits', ['url' => 'https://example.com/'], ['Origin' => 'https://frontend.test']);
        $id = $create->json('jobId');
        $token = $create->json('accessToken');

        // Header
        $resHeader = $this->get("/audits/{$id}", [
            'Origin' => 'https://frontend.test',
            'Authorization' => "Bearer {$token}",
        ]);
        $resHeader->assertStatus(200);

        // Query param
        $resQuery = $this->get("/audits/{$id}?accessToken=" . urlencode($token), [
            'Origin' => 'https://frontend.test',
        ]);
        $resQuery->assertStatus(200);

        $this->assertSame($resHeader->json(), $resQuery->json());
    }

    /**
     * UC-FAIL-15: unknown and abnormal job IDs
     */
    #[DataProvider('abnormalJobIdProvider')]
    public function test_UC_FAIL_15_unknown_or_malformed_job_ids(string $weirdId, string $expectedMessage): void
    {
        $response = $this->get('/audits/' . urlencode($weirdId), [
            'Origin' => 'https://frontend.test',
            'Authorization' => 'Bearer some-random-token-value-here',
        ]);

        $response->assertStatus(404);
        $response->assertJson([
            'error' => 'not-found',
            'message' => $expectedMessage,
        ]);
    }

    public static function abnormalJobIdProvider(): array
    {
        return [
            'non-existent id' => ['aud_nonexistent1234567890123', 'Audit tidak ditemukan.'],
            'very long id' => ['aud_' . str_repeat('z', 500), 'Audit tidak ditemukan.'],
            'unicode id' => ['aud_監査ジョブ_12345', 'Audit tidak ditemukan.'],
            'sql characters' => ["aud_' OR '1'='1", 'Audit tidak ditemukan.'],
            'path-like id' => ['aud_../../etc/passwd', 'Endpoint tidak ditemukan.'],
        ];
    }

    /**
     * UC-FAIL-16: unknown artifact names
     */
    #[DataProvider('unknownArtifactNameProvider')]
    public function test_UC_FAIL_16_unknown_artifact_names(string $artifactName): void
    {
        $create = $this->postJson('/audits', ['url' => 'https://example.com/'], ['Origin' => 'https://frontend.test']);
        $id = $create->json('jobId');
        $token = $create->json('accessToken');

        $this->worker->processNext();

        $resultRes = $this->get("/audits/{$id}/result", [
            'Origin' => 'https://frontend.test',
            'Authorization' => "Bearer {$token}",
        ]);
        preg_match('/token=([^&]+)/', $resultRes->json('links.reader'), $m);
        $shareToken = $m[1] ?? '';

        $response = $this->get("/audits/{$id}/artifacts/{$artifactName}?token={$shareToken}", [
            'Origin' => 'https://frontend.test',
        ]);

        $response->assertStatus(404);
        $response->assertJson([
            'error' => 'not-found',
            'message' => 'Artefak tidak ditemukan.',
        ]);
    }

    public static function unknownArtifactNameProvider(): array
    {
        return [
            'unknown binary' => ['unknown.bin'],
            'secret text file' => ['secret.txt'],
            'jobs.sqlite' => ['jobs.sqlite'],
            'traversal attempt' => ['../../secret.txt'],
            'report html route (not in artifacts list)' => ['report.html'],
        ];
    }

    /**
     * UC-FAIL-17: result requested before completion returns 409 not-ready
     */
    public function test_UC_FAIL_17_result_requested_before_completion(): void
    {
        $create = $this->postJson('/audits', ['url' => 'https://example.com/'], ['Origin' => 'https://frontend.test']);
        $id = $create->json('jobId');
        $token = $create->json('accessToken');

        // 1. While queued
        $queuedRes = $this->get("/audits/{$id}/result", [
            'Origin' => 'https://frontend.test',
            'Authorization' => "Bearer {$token}",
        ]);
        $queuedRes->assertStatus(409);
        $this->assertSame('not-ready', $queuedRes->json('error'));
        $this->assertSame('queued', $queuedRes->json('job.status'));

        // 2. While running
        $this->store->claimNext();
        $runningRes = $this->get("/audits/{$id}/result", [
            'Origin' => 'https://frontend.test',
            'Authorization' => "Bearer {$token}",
        ]);
        $runningRes->assertStatus(409);
        $this->assertSame('not-ready', $runningRes->json('error'));
        $this->assertSame('running', $runningRes->json('job.status'));

        // 3. While cancelled
        $this->store->cancelRunning($id);
        $cancelledRes = $this->get("/audits/{$id}/result", [
            'Origin' => 'https://frontend.test',
            'Authorization' => "Bearer {$token}",
        ]);
        $cancelledRes->assertStatus(409);
        $this->assertSame('not-ready', $cancelledRes->json('error'));
        $this->assertSame('cancelled', $cancelledRes->json('job.status'));
    }

    /**
     * UC-FAIL-18: cancelling an already completed, failed, or cancelled job
     */
    public function test_UC_FAIL_18_cancelling_already_completed_or_cancelled_job(): void
    {
        $create = $this->postJson('/audits', ['url' => 'https://example.com/'], ['Origin' => 'https://frontend.test']);
        $id = $create->json('jobId');
        $token = $create->json('accessToken');

        // First cancel -> 202, status becomes cancelled
        $cancel1 = $this->delete("/audits/{$id}", [], [
            'Origin' => 'https://frontend.test',
            'Authorization' => "Bearer {$token}",
        ]);
        $cancel1->assertStatus(202);
        $this->assertSame('cancelled', $cancel1->json('job.status'));

        // Second cancel -> returns 202, status remains cancelled
        $cancel2 = $this->delete("/audits/{$id}", [], [
            'Origin' => 'https://frontend.test',
            'Authorization' => "Bearer {$token}",
        ]);
        $cancel2->assertStatus(202);
        $this->assertSame('cancelled', $cancel2->json('job.status'));

        // Complete another job and try to cancel it
        $createCompleted = $this->postJson('/audits', ['url' => 'https://example.com/done'], ['Origin' => 'https://frontend.test']);
        $doneId = $createCompleted->json('jobId');
        $doneToken = $createCompleted->json('accessToken');

        $this->worker->processNext();
        $this->assertSame('completed', $this->store->getJob($doneId)?->status);

        // Cancel on completed job returns 202, job remains completed
        $cancelCompleted = $this->delete("/audits/{$doneId}", [], [
            'Origin' => 'https://frontend.test',
            'Authorization' => "Bearer {$doneToken}",
        ]);
        $cancelCompleted->assertStatus(202);
        $this->assertSame('completed', $cancelCompleted->json('job.status'));
    }

    /**
     * UC-FAIL-19: rate limiting exact boundary and Retry-After header
     */
    public function test_UC_FAIL_19_rate_limit_exact_boundary_and_retry_after(): void
    {
        // Limit configured as 3 in setUp()
        // 1st request -> OK (202)
        $this->postJson('/audits', ['url' => 'https://example.com/1'], ['Origin' => 'https://frontend.test'])->assertStatus(202);

        // 2nd request -> OK (202)
        $this->postJson('/audits', ['url' => 'https://example.com/2'], ['Origin' => 'https://frontend.test'])->assertStatus(202);

        // 3rd request -> OK (202, exact boundary)
        $this->postJson('/audits', ['url' => 'https://example.com/3'], ['Origin' => 'https://frontend.test'])->assertStatus(202);

        // 4th request (1 over limit) -> 429
        $fourth = $this->postJson('/audits', ['url' => 'https://example.com/4'], ['Origin' => 'https://frontend.test']);
        $fourth->assertStatus(429);
        $fourth->assertHeader('Retry-After', '3600');
        $fourth->assertJson([
            'error' => 'rate-limit',
            'message' => 'Batas 3 audit per jam sudah tercapai.',
        ]);
    }

    /**
     * UC-FAIL-20: rate limit window reset after 1 hour
     */
    public function test_UC_FAIL_20_rate_limit_window_reset(): void
    {
        // Consume all 3 quota slots with timestamps 2 hours ago
        $twoHoursAgo = (int) (microtime(true) * 1000) - (2 * 3600 * 1000);
        $ipHash = SecurityService::digest('127.0.0.1', $this->config->secret);

        for ($i = 1; $i <= 3; $i++) {
            $this->store->createJob([
                'id' => "aud_old_job_{$i}",
                'tokenHash' => SecurityService::digest("t_{$i}", $this->config->secret),
                'shareTokenHash' => SecurityService::digest("s_{$i}", $this->config->secret),
                'ipHash' => $ipHash,
                'targetUrl' => "https://example.com/{$i}",
                'createdAt' => $twoHoursAgo,
                'expiresAt' => $twoHoursAgo + 3600000,
                'artifactDir' => $this->artifactsDir . DIRECTORY_SEPARATOR . "aud_old_job_{$i}",
            ]);
        }

        // Now new request should succeed because the old requests are outside the 1-hour window
        $res = $this->postJson('/audits', ['url' => 'https://example.com/fresh'], ['Origin' => 'https://frontend.test']);
        $res->assertStatus(202);
    }

    /**
     * UC-FAIL-21: rate limit IP isolation and spoofing prevention
     */
    public function test_UC_FAIL_21_rate_limit_ip_isolation_and_spoofing(): void
    {
        // TRUST_PROXY is false by default in config
        // Send 3 requests with spoofed X-Forwarded-For header; they all map to direct client IP
        for ($i = 1; $i <= 3; $i++) {
            $this->call('POST', '/audits', [], [], [], [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ORIGIN' => 'https://frontend.test',
                'HTTP_X_FORWARDED_FOR' => "10.0.0.{$i}",
                'REMOTE_ADDR' => '192.168.1.100',
            ], json_encode(['url' => "https://example.com/a{$i}"]))->assertStatus(202);
        }

        // 4th request from same REMOTE_ADDR with another spoofed header is blocked
        $blocked = $this->call('POST', '/audits', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ORIGIN' => 'https://frontend.test',
            'HTTP_X_FORWARDED_FOR' => '10.0.0.99',
            'REMOTE_ADDR' => '192.168.1.100',
        ], json_encode(['url' => 'https://example.com/blocked']));
        $blocked->assertStatus(429);

        // However, a request from a genuinely different REMOTE_ADDR is accepted!
        $differentIp = $this->call('POST', '/audits', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ORIGIN' => 'https://frontend.test',
            'REMOTE_ADDR' => '192.168.1.200',
        ], json_encode(['url' => 'https://example.com/other-ip']));
        $differentIp->assertStatus(202);
    }

    /**
     * UC-FAIL-22: artifact file missing on disk returns 404
     */
    public function test_UC_FAIL_22_artifact_file_missing_on_disk(): void
    {
        $create = $this->postJson('/audits', ['url' => 'https://example.com/'], ['Origin' => 'https://frontend.test']);
        $id = $create->json('jobId');
        $token = $create->json('accessToken');

        $this->worker->processNext();

        $resultRes = $this->get("/audits/{$id}/result", [
            'Origin' => 'https://frontend.test',
            'Authorization' => "Bearer {$token}",
        ]);
        $readerLink = $resultRes->json('links.reader');

        // Manually delete the physical reader.html file from disk
        $job = $this->store->getJob($id);
        $readerFile = $job->artifactDir . DIRECTORY_SEPARATOR . 'reader.html';
        $this->assertFileExists($readerFile);
        unlink($readerFile);

        // Accessing the link now returns 404
        $res = $this->get($readerLink, ['Origin' => 'https://frontend.test']);
        $res->assertStatus(404);
        $res->assertJson([
            'error' => 'not-found',
            'message' => 'Artefak tidak ditemukan.',
        ]);
    }

    /**
     * UC-FAIL-23: corrupted checkpoint recovery fallback
     */
    public function test_UC_FAIL_23_corrupted_checkpoint_recovery_fallback(): void
    {
        $dir = $this->tempDir . DIRECTORY_SEPARATOR . 'corrupted_ck';
        mkdir($dir, 0777, true);

        // Corrupted snapshot.json
        file_put_contents($dir . DIRECTORY_SEPARATOR . 'snapshot.json', '{invalid-json');
        file_put_contents($dir . DIRECTORY_SEPARATOR . 'engine-result.json', '{"ok": true}');

        $job = new AuditJob(
            id: 'aud_corrupt_123',
            tokenHash: str_repeat('a', 64),
            shareTokenHash: str_repeat('b', 64),
            ipHash: str_repeat('c', 64),
            targetUrl: 'https://example.com/',
            status: 'running',
            stage: 'Menyiapkan',
            progress: 5,
            attempts: 1,
            cancelRequested: false,
            result: null,
            errorCode: null,
            errorMessage: null,
            createdAt: 1000,
            updatedAt: 1000,
            startedAt: 1000,
            finishedAt: null,
            expiresAt: 60000,
            artifactDir: $dir
        );

        $bridgeCalled = false;
        $this->bridge->setAuditHandler(function () use (&$bridgeCalled) {
            $bridgeCalled = true;
            return [
                'type' => 'result',
                'snapshot' => ['recovered_cleanly' => true],
            ];
        });

        $auditor = new Auditor($this->bridge, new ReportRenderer());
        $result = $auditor->audit($job);

        // Fallback occurred: bridge was called because checkpoint was corrupted
        $this->assertTrue($bridgeCalled);
        $this->assertSame(['recovered_cleanly' => true], $result['snapshot']);
    }

    /**
     * UC-FAIL-24: bridge process crash or invalid NDJSON handled cleanly
     */
    public function test_UC_FAIL_24_bridge_process_crash_or_invalid_ndjson(): void
    {
        $job = $this->store->createJob([
            'id' => 'aud_crash_test',
            'tokenHash' => str_repeat('a', 64),
            'shareTokenHash' => str_repeat('b', 64),
            'ipHash' => str_repeat('c', 64),
            'targetUrl' => 'https://example.com/',
            'createdAt' => (int) (microtime(true) * 1000),
            'expiresAt' => (int) (microtime(true) * 1000) + 60000,
            'artifactDir' => $this->artifactsDir . DIRECTORY_SEPARATOR . 'aud_crash_test',
        ]);

        $this->auditor->setHandler(function () {
            throw new AuditFailedException('bridge-failed', null, 'Bridge process exited unexpectedly with code 1');
        });

        // Worker executes job
        $this->worker->processNext();

        $updated = $this->store->getJob('aud_crash_test');
        // Because bridge-failed is not transient, it fails immediately
        $this->assertSame('failed', $updated?->status);
        $this->assertSame('bridge-failed', $updated?->errorCode);
        $this->assertStringContainsString('Bridge process exited unexpectedly', $updated?->errorMessage ?? '');
    }

    /**
     * UC-FAIL-25: bridge execution timeout triggers audit-timeout error
     */
    public function test_UC_FAIL_25_bridge_execution_timeout(): void
    {
        $job = $this->store->createJob([
            'id' => 'aud_timeout_test',
            'tokenHash' => str_repeat('a', 64),
            'shareTokenHash' => str_repeat('b', 64),
            'ipHash' => str_repeat('c', 64),
            'targetUrl' => 'https://example.com/',
            'createdAt' => (int) (microtime(true) * 1000),
            'expiresAt' => (int) (microtime(true) * 1000) + 60000,
            'artifactDir' => $this->artifactsDir . DIRECTORY_SEPARATOR . 'aud_timeout_test',
        ]);

        $this->auditor->setHandler(function (AuditJob $job, ?callable $onProgress, ?callable $shouldCancel) {
            // Wait longer than worker timeout (200ms)
            usleep(250000);
            if ($shouldCancel !== null && $shouldCancel()) {
                throw new \App\Exceptions\AuditTimeoutException('Audit melewati batas waktu.');
            }
            return ['snapshot' => [], 'artifacts' => []];
        });

        $this->worker->processNext();

        $updated = $this->store->getJob('aud_timeout_test');
        $this->assertSame('failed', $updated?->status);
        $this->assertSame('audit-timeout', $updated?->errorCode);
        $this->assertStringContainsString('Audit melewati batas', $updated?->errorMessage ?? '');
    }

    /**
     * UC-FAIL-26: PDF rendering failure falls back to HTML with warning
     */
    public function test_UC_FAIL_26_pdf_rendering_fallback_with_warning(): void
    {
        $dir = $this->tempDir . DIRECTORY_SEPARATOR . 'pdf_fail';
        mkdir($dir, 0777, true);
        file_put_contents($dir . DIRECTORY_SEPARATOR . 'engine-result.json', json_encode(['score' => 90]));

        $job = new AuditJob(
            id: 'aud_pdf_fail_123',
            tokenHash: str_repeat('a', 64),
            shareTokenHash: str_repeat('b', 64),
            ipHash: str_repeat('c', 64),
            targetUrl: 'https://example.com/',
            status: 'running',
            stage: 'Menyiapkan',
            progress: 5,
            attempts: 1,
            cancelRequested: false,
            result: null,
            errorCode: null,
            errorMessage: null,
            createdAt: 1000,
            updatedAt: 1000,
            startedAt: 1000,
            finishedAt: null,
            expiresAt: 60000,
            artifactDir: $dir
        );

        $this->bridge->setRenderPdfHandler(function () {
            throw new \RuntimeException('Chromium low memory: failed to launch');
        });

        $auditor = new Auditor($this->bridge, new ReportRenderer());
        $result = $auditor->audit($job);

        // Fallback happened: report.pdf points to report.html
        $this->assertSame('report.html', $result['artifacts']['reportPdf']);
        $this->assertFileExists($dir . DIRECTORY_SEPARATOR . 'report.html');
        $this->assertFileDoesNotExist($dir . DIRECTORY_SEPARATOR . 'report.pdf');
    }

    /**
     * UC-FAIL-27: config rejects short secret in production
     */
    public function test_UC_FAIL_27_config_short_secret_in_production(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('BACKEND_SECRET minimal 32 karakter di production.');

        new AksaraConfig([], [
            'NODE_ENV' => 'production',
            'BACKEND_SECRET' => 'short-secret-less-than-32-chars',
        ]);
    }

    /**
     * UC-FAIL-28: config validates invalid numeric ranges with Indonesian error messages
     */
    #[DataProvider('invalidConfigNumberProvider')]
    public function test_UC_FAIL_28_config_invalid_numeric_ranges(string $key, string $invalidValue, string $expectedMessage): void
    {
        try {
            new AksaraConfig([], [$key => $invalidValue]);
            $this->fail("Expected InvalidArgumentException for {$key}={$invalidValue}");
        } catch (InvalidArgumentException $e) {
            $this->assertSame($expectedMessage, $e->getMessage());
        }
    }

    public static function invalidConfigNumberProvider(): array
    {
        return [
            'PORT out of range high' => ['PORT', '99999', 'PORT harus berupa bilangan bulat 1–65535.'],
            'PORT zero' => ['PORT', '0', 'PORT harus berupa bilangan bulat 1–65535.'],
            'PORT string' => ['PORT', 'not-a-port', 'PORT harus berupa bilangan bulat 1–65535.'],
            'AUDIT_TIMEOUT_MS below min' => ['AUDIT_TIMEOUT_MS', '15000', 'AUDIT_TIMEOUT_MS harus berupa bilangan bulat 30000–600000.'],
            'AUDIT_RATE_LIMIT above max' => ['AUDIT_RATE_LIMIT', '101', 'AUDIT_RATE_LIMIT harus berupa bilangan bulat 1–100.'],
            'MAX_BODY_BYTES float' => ['MAX_BODY_BYTES', '2048.5', 'MAX_BODY_BYTES harus berupa bilangan bulat 1024–65536.'],
        ];
    }

    /**
     * UC-FAIL-29: empty or invalid FRONTEND_ORIGINS configuration
     */
    public function test_UC_FAIL_29_config_empty_frontend_origins(): void
    {
        // When FRONTEND_ORIGINS is empty, origins list becomes empty
        $config = new AksaraConfig([], ['FRONTEND_ORIGINS' => '']);
        $this->assertSame([], $config->frontendOrigins);

        // When origins list is empty, any browser request with Origin header gets 403
        $this->app->instance(AksaraConfig::class, $config);

        $response = $this->get('/health', ['Origin' => 'https://any-site.com']);
        $response->assertStatus(403);
        $response->assertJson([
            'error' => 'origin-not-allowed',
            'message' => 'Origin frontend tidak diizinkan.',
        ]);
    }
}
