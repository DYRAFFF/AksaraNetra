<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\AksaraConfig;
use App\Services\AuditWorkerInterface;
use App\Services\BridgeClientInterface;
use App\Services\JobStore;
use Illuminate\Testing\TestResponse;
use Tests\Fakes\FakeAuditWorker;
use Tests\TestCase;

class ApiTest extends TestCase
{
    private string $tempDir;
    private AksaraConfig $config;
    private JobStore $store;
    private FakeAuditWorker $worker;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'aksara-api-' . uniqid();
        mkdir($this->tempDir, 0777, true);
        mkdir($this->tempDir . DIRECTORY_SEPARATOR . 'artifacts', 0777, true);

        $dbPath = $this->tempDir . DIRECTORY_SEPARATOR . 'jobs.sqlite';

        $this->config = new AksaraConfig([
            'dataDir' => $this->tempDir,
            'databasePath' => $dbPath,
            'artifactsDir' => $this->tempDir . DIRECTORY_SEPARATOR . 'artifacts',
            'secret' => 'test-secret-that-is-long-enough-123456',
            'frontendOrigins' => ['https://frontend.test'],
            'rateLimit' => 10,
            'retentionMs' => 3600000,
            'maxBodyBytes' => 65536,
        ]);

        $this->store = new JobStore($dbPath);
        $this->worker = new FakeAuditWorker($this->store);

        $this->app->instance(AksaraConfig::class, $this->config);
        $this->app->instance(JobStore::class, $this->store);
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

    private function createJob(string $url = 'https://example.com/', array $extra = []): TestResponse
    {
        return $this->postJson('/audits', array_merge(['url' => $url], $extra), [
            'Origin' => 'https://frontend.test',
        ]);
    }

    public function test_creates_anonymous_job_and_hides_without_token(): void
    {
        $response = $this->createJob();
        $response->assertStatus(202);

        $data = $response->json();
        $this->assertMatchesRegularExpression('/^aud_/', $data['jobId']);
        $this->assertGreaterThan(30, strlen($data['accessToken']));
        $this->assertSame(1, $this->worker->kicks);
        $this->assertSame('/audits/' . $data['jobId'], $data['statusPath']);
        $response->assertHeader('Location', '/audits/' . $data['jobId']);

        // Hidden without token
        $hidden = $this->get('/audits/' . $data['jobId'], [
            'Origin' => 'https://frontend.test',
        ]);
        $hidden->assertStatus(404);

        // Visible with Bearer token in header
        $visible = $this->get('/audits/' . $data['jobId'], [
            'Origin' => 'https://frontend.test',
            'Authorization' => 'Bearer ' . $data['accessToken'],
        ]);
        $visible->assertStatus(200);
        $this->assertSame('queued', $visible->json('job.status'));
        $this->assertSame('https://example.com/', $visible->json('job.url'));

        // Visible with token in query param
        $visibleQuery = $this->get('/audits/' . $data['jobId'] . '?accessToken=' . $data['accessToken'], [
            'Origin' => 'https://frontend.test',
        ]);
        $visibleQuery->assertStatus(200);
        $this->assertSame('queued', $visibleQuery->json('job.status'));
    }

    public function test_returns_expiring_reader_and_pdf_links_only_after_completion(): void
    {
        $response = $this->createJob();
        $data = $response->json();

        // Before completion, /result returns 409 not-ready
        $notReady = $this->get('/audits/' . $data['jobId'] . '/result', [
            'Origin' => 'https://frontend.test',
            'Authorization' => 'Bearer ' . $data['accessToken'],
        ]);
        $notReady->assertStatus(409);
        $this->assertSame('not-ready', $notReady->json('error'));

        // Claim job and write artifacts
        $job = $this->store->claimNext();
        $this->assertNotNull($job);

        mkdir($job->artifactDir, 0777, true);
        file_put_contents($job->artifactDir . DIRECTORY_SEPARATOR . 'reader.html', '<!doctype html><title>Reader</title>');
        file_put_contents($job->artifactDir . DIRECTORY_SEPARATOR . 'patched.html', '<!doctype html><title>Patched</title>');
        file_put_contents($job->artifactDir . DIRECTORY_SEPARATOR . 'report.pdf', '%PDF-test');
        file_put_contents($job->artifactDir . DIRECTORY_SEPARATOR . 'after.png', 'png');

        $this->store->complete($job->id, ['snapshot' => ['summary' => ['beforeTotal' => 2, 'afterTotal' => 0]]]);

        // After completion, /result returns 200 with artifact links
        $result = $this->get('/audits/' . $job->id . '/result', [
            'Origin' => 'https://frontend.test',
            'Authorization' => 'Bearer ' . $data['accessToken'],
        ]);
        $result->assertStatus(200);

        $resultData = $result->json();
        $this->assertStringContainsString('token=', $resultData['links']['reader']);
        $this->assertStringContainsString('token=', $resultData['links']['reportPdf']);

        // Download reader artifact
        $reader = $this->get($resultData['links']['reader'], [
            'Origin' => 'https://frontend.test',
        ]);
        $reader->assertStatus(200);
        $reader->assertHeader('Content-Type', 'text/html; charset=utf-8');
        $cacheControl = (string) $reader->headers->get('Cache-Control');
        $this->assertStringContainsString('private', $cacheControl);
        $this->assertStringContainsString('max-age=300', $cacheControl);
        $reader->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('Reader', file_get_contents($reader->getFile()->getPathname()));

        // Artifact request without token returns 404
        $noToken = $this->get("/audits/{$job->id}/artifacts/reader", [
            'Origin' => 'https://frontend.test',
        ]);
        $noToken->assertStatus(404);
        $this->assertSame('Artefak tidak ditemukan.', $noToken->json('message'));

        // Artifact request with invalid token returns 404
        $invalidToken = $this->get("/audits/{$job->id}/artifacts/reader?token=wrong-token", [
            'Origin' => 'https://frontend.test',
        ]);
        $invalidToken->assertStatus(404);
        $this->assertSame('Artefak tidak ditemukan.', $invalidToken->json('message'));
    }

    public function test_lets_user_explicitly_reuse_stored_result(): void
    {
        $first = $this->createJob('https://example.com/');
        $firstJob = $this->store->claimNext();
        $this->assertNotNull($firstJob);

        mkdir($firstJob->artifactDir, 0777, true);
        file_put_contents($firstJob->artifactDir . DIRECTORY_SEPARATOR . 'reader.html', 'stored reader');
        $this->store->complete($firstJob->id, ['snapshot' => ['cached' => true]]);

        // Check cache lookup
        $lookup = $this->get('/audits/cache?url=' . urlencode('https://example.com/'), [
            'Origin' => 'https://frontend.test',
        ]);
        $lookup->assertStatus(200);
        $this->assertTrue($lookup->json('available'));
        $this->assertNotNull($lookup->json('capturedAt'));
        $this->assertNotNull($lookup->json('expiresAt'));

        // Create job with reuseExisting: true
        $reused = $this->createJob('https://example.com/', ['reuseExisting' => true]);
        $reused->assertStatus(200);
        $this->assertSame('completed', $reused->json('status'));
        $this->assertSame(1, $this->worker->kicks);

        $storedReused = $this->store->getJob($reused->json('jobId'));
        $this->assertTrue($storedReused->result['snapshot']['cached']);
        $this->assertFileExists($storedReused->artifactDir . DIRECTORY_SEPARATOR . 'reader.html');
        $this->assertSame('stored reader', file_get_contents($storedReused->artifactDir . DIRECTORY_SEPARATOR . 'reader.html'));
    }

    public function test_enforces_rate_limit_and_queued_cancellation(): void
    {
        // Reconfigure with rateLimit = 1
        $this->config = new AksaraConfig([
            'dataDir' => $this->tempDir,
            'databasePath' => $this->tempDir . DIRECTORY_SEPARATOR . 'jobs.sqlite',
            'artifactsDir' => $this->tempDir . DIRECTORY_SEPARATOR . 'artifacts',
            'secret' => 'test-secret-that-is-long-enough-123456',
            'frontendOrigins' => ['https://frontend.test'],
            'rateLimit' => 1,
            'retentionMs' => 3600000,
        ]);
        $this->app->instance(AksaraConfig::class, $this->config);

        $first = $this->createJob('https://example.com/');
        $first->assertStatus(202);

        $second = $this->createJob('https://example.org/');
        $second->assertStatus(429);
        $second->assertHeader('Retry-After', '3600');
        $second->assertJson([
            'error' => 'rate-limit',
            'message' => 'Batas 1 audit per jam sudah tercapai.',
        ]);

        // Cancel the first job
        $cancelled = $this->delete('/audits/' . $first->json('jobId'), [], [
            'Origin' => 'https://frontend.test',
            'Authorization' => 'Bearer ' . $first->json('accessToken'),
        ]);
        $cancelled->assertStatus(202);
        $this->assertSame('cancelled', $cancelled->json('job.status'));
    }

    public function test_json_body_validation_and_size_limits(): void
    {
        // 415 on wrong Content-Type
        $wrongType = $this->call('POST', '/audits', [], [], [], [
            'CONTENT_TYPE' => 'text/plain',
            'HTTP_ORIGIN' => 'https://frontend.test',
        ], 'some plain text');
        $wrongType->assertStatus(415);
        $wrongType->assertJson([
            'error' => 'bad-request',
            'message' => 'Gunakan Content-Type application/json.',
        ]);

        // 400 on invalid JSON
        $invalidJson = $this->call('POST', '/audits', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ORIGIN' => 'https://frontend.test',
        ], '{malformed json');
        $invalidJson->assertStatus(400);
        $invalidJson->assertJson([
            'error' => 'bad-request',
            'message' => 'JSON tidak valid.',
        ]);

        // 413 on payload too large
        $this->config = new AksaraConfig([
            'dataDir' => $this->tempDir,
            'databasePath' => $this->tempDir . DIRECTORY_SEPARATOR . 'jobs.sqlite',
            'artifactsDir' => $this->tempDir . DIRECTORY_SEPARATOR . 'artifacts',
            'secret' => 'test-secret-that-is-long-enough-123456',
            'frontendOrigins' => ['https://frontend.test'],
            'maxBodyBytes' => 1024,
            'retentionMs' => 3600000,
        ]);
        $this->app->instance(AksaraConfig::class, $this->config);

        $tooLarge = $this->call('POST', '/audits', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ORIGIN' => 'https://frontend.test',
        ], json_encode(['url' => 'https://example.com/', 'extra' => str_repeat('a', 2000)]));
        $tooLarge->assertStatus(413);
        $tooLarge->assertJson([
            'error' => 'bad-request',
            'message' => 'Body permintaan terlalu besar.',
        ]);
    }

    public function test_health_endpoint(): void
    {
        $response = $this->get('/health');
        $response->assertStatus(200);
        $response->assertJson([
            'ok' => true,
            'worker' => 'ready',
            'concurrency' => 1,
        ]);
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function test_not_found_endpoint(): void
    {
        $response = $this->get('/non-existent-endpoint');
        $response->assertStatus(404);
        $response->assertJson([
            'error' => 'not-found',
            'message' => 'Endpoint tidak ditemukan.',
        ]);
    }
}
