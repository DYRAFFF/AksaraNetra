<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases;

use App\Console\Commands\AuditCleanupCommand;
use App\Exceptions\AuditCancelledException;
use App\Exceptions\AuditFailedException;
use App\Models\AuditJob;
use App\Services\AksaraConfig;
use App\Services\AuditWorker;
use App\Services\AuditWorkerInterface;
use App\Services\JobStore;
use App\Services\SecurityService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Testing\TestResponse;
use Tests\Fakes\FakeAuditor;
use Tests\TestCase;

/**
 * Step C: Real user scenarios (prefix UC-USR)
 * End-to-end feature tests walking through the whole story via the HTTP API.
 */
class UserScenarioTest extends TestCase
{
    private string $tempDir;
    private string $artifactsDir;
    private string $dbPath;
    private AksaraConfig $config;
    private JobStore $store;
    private FakeAuditor $auditor;
    private AuditWorker $worker;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'aksara-usr-' . uniqid();
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
            'rateLimit' => 20,
            'retentionMs' => 3600000, // 1 hour
            'maxBodyBytes' => 65536,
        ]);

        $this->store = new JobStore($this->dbPath);
        $this->auditor = new FakeAuditor();
        $this->worker = new AuditWorker(
            store: $this->store,
            auditor: $this->auditor,
            timeoutMs: 5000,
            concurrency: 1
        );

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

    private function postAudit(string $url = 'https://example.com/', array $extra = []): TestResponse
    {
        return $this->postJson('/audits', array_merge(['url' => $url], $extra), [
            'Origin' => 'https://frontend.test',
        ]);
    }

    /**
     * UC-USR-01: Happy path
     * Create an audit, poll status (queued, running with progress, completed),
     * fetch the result, download each artifact via the returned links.
     */
    public function test_UC_USR_01_happy_path_walks_full_audit_lifecycle(): void
    {
        // 1. Create audit
        $createRes = $this->postAudit('https://example.com/');
        $createRes->assertStatus(202);

        $jobId = $createRes->json('jobId');
        $accessToken = $createRes->json('accessToken');
        $this->assertNotEmpty($jobId);
        $this->assertNotEmpty($accessToken);
        $this->assertSame('queued', $createRes->json('status'));
        $createRes->assertHeader('Location', "/audits/{$jobId}");

        // 2. Poll initial status: queued
        $pollQueued = $this->get("/audits/{$jobId}", [
            'Origin' => 'https://frontend.test',
            'Authorization' => "Bearer {$accessToken}",
        ]);
        $pollQueued->assertStatus(200);
        $this->assertSame('queued', $pollQueued->json('job.status'));
        $this->assertSame(0, $pollQueued->json('job.progress'));
        $this->assertNull($pollQueued->json('job.startedAt'));

        // 3. Worker claims and processes job with progress milestones
        $progressStages = [];
        $this->auditor->setHandler(function (AuditJob $job, ?callable $onProgress) use (&$progressStages) {
            if ($onProgress !== null) {
                $onProgress(['progress' => 30, 'stage' => 'Memeriksa DOM']);
                $onProgress(['progress' => 70, 'stage' => 'Mengevaluasi kontras warna']);
            }

            mkdir($job->artifactDir, 0777, true);
            file_put_contents($job->artifactDir . DIRECTORY_SEPARATOR . 'reader.html', '<!doctype html><title>Reader</title>');
            file_put_contents($job->artifactDir . DIRECTORY_SEPARATOR . 'patched.html', '<!doctype html><title>Patched</title>');
            file_put_contents($job->artifactDir . DIRECTORY_SEPARATOR . 'report.pdf', '%PDF-1.4 report');
            file_put_contents($job->artifactDir . DIRECTORY_SEPARATOR . 'after.png', 'PNG-screenshot-data');

            return [
                'snapshot' => [
                    'engineVersion' => '0.3.0',
                    'capturedAt' => date('c'),
                    'disclaimer' => 'Disclaimer text',
                    'source' => [
                        'requestedUrl' => 'https://example.com/',
                        'finalUrl' => 'https://example.com/',
                        'title' => 'Example Domain',
                        'status' => 200,
                    ],
                    'summary' => [
                        'beforeTotal' => 5,
                        'afterTotal' => 1,
                        'reduction' => 4,
                        'reductionPercent' => 80.0,
                        'strictImprovement' => true,
                        'noRegression' => true,
                    ],
                    'rules' => ['color-contrast' => ['before' => 3, 'after' => 0]],
                    'counts' => ['fixed' => 4, 'review' => 1, 'skipped' => 0, 'rolledBack' => 0, 'verifiedOverrides' => 0],
                    'warnings' => [
                        'rolledBack' => false,
                        'wcagRegressionClean' => true,
                        'worsenedRules' => [],
                        'duplicateLabels' => [],
                        'staleOverrides' => [],
                        'rolledBackRecords' => [],
                    ],
                ],
                'artifacts' => [
                    'reader' => 'reader.html',
                    'patched' => 'patched.html',
                    'reportPdf' => 'report.pdf',
                    'screenshot' => 'after.png',
                ],
            ];
        });

        // Run worker once
        $processed = $this->worker->processNext();
        $this->assertTrue($processed);

        // 4. Poll completed status
        $pollCompleted = $this->get("/audits/{$jobId}", [
            'Origin' => 'https://frontend.test',
            'Authorization' => "Bearer {$accessToken}",
        ]);
        $pollCompleted->assertStatus(200);
        $this->assertSame('completed', $pollCompleted->json('job.status'));
        $this->assertSame(100, $pollCompleted->json('job.progress'));
        $this->assertNotNull($pollCompleted->json('job.startedAt'));
        $this->assertNotNull($pollCompleted->json('job.finishedAt'));

        // 5. Fetch result
        $resultRes = $this->get("/audits/{$jobId}/result", [
            'Origin' => 'https://frontend.test',
            'Authorization' => "Bearer {$accessToken}",
        ]);
        $resultRes->assertStatus(200);
        $this->assertSame(5, $resultRes->json('result.snapshot.summary.beforeTotal'));
        $links = $resultRes->json('links');
        $this->assertArrayHasKey('reader', $links);
        $this->assertArrayHasKey('patched', $links);
        $this->assertArrayHasKey('reportPdf', $links);
        $this->assertArrayHasKey('screenshot', $links);

        // 6. Download each artifact via returned links
        // Reader
        $readerRes = $this->get($links['reader'], ['Origin' => 'https://frontend.test']);
        $readerRes->assertStatus(200);
        $readerRes->assertHeader('Content-Type', 'text/html; charset=utf-8');
        $readerRes->assertHeader('X-Content-Type-Options', 'nosniff');

        // Patched
        $patchedRes = $this->get($links['patched'], ['Origin' => 'https://frontend.test']);
        $patchedRes->assertStatus(200);
        $patchedRes->assertHeader('Content-Type', 'text/html; charset=utf-8');

        // Report PDF
        $pdfRes = $this->get($links['reportPdf'], ['Origin' => 'https://frontend.test']);
        $pdfRes->assertStatus(200);
        $pdfRes->assertHeader('Content-Type', 'application/pdf');

        // Screenshot PNG
        $screenshotRes = $this->get($links['screenshot'], ['Origin' => 'https://frontend.test']);
        $screenshotRes->assertStatus(200);
        $screenshotRes->assertHeader('Content-Type', 'image/png');
    }

    /**
     * UC-USR-02: Cache flow
     * Cache lookup says not available, finish an audit, lookup says available,
     * create again with reuseExisting: true (completes immediately, artifacts copied),
     * and with reuseExisting: false (new real audit).
     */
    public function test_UC_USR_02_cache_flow_lookup_miss_hit_and_reuse_toggle(): void
    {
        $targetUrl = 'https://cached-site.test/article';

        // 1. Initial cache lookup -> false
        $lookupBefore = $this->get('/audits/cache?url=' . urlencode($targetUrl), [
            'Origin' => 'https://frontend.test',
        ]);
        $lookupBefore->assertStatus(200);
        $this->assertFalse($lookupBefore->json('available'));
        $this->assertNull($lookupBefore->json('capturedAt'));
        $this->assertNull($lookupBefore->json('expiresAt'));

        // 2. Perform initial audit
        $postRes = $this->postAudit($targetUrl);
        $jobId1 = $postRes->json('jobId');

        $this->worker->processNext();
        $job1 = $this->store->getJob($jobId1);
        $this->assertSame('completed', $job1?->status);

        // 3. Cache lookup now -> true
        $lookupAfter = $this->get('/audits/cache?url=' . urlencode($targetUrl), [
            'Origin' => 'https://frontend.test',
        ]);
        $lookupAfter->assertStatus(200);
        $this->assertTrue($lookupAfter->json('available'));
        $this->assertNotNull($lookupAfter->json('capturedAt'));
        $this->assertNotNull($lookupAfter->json('expiresAt'));

        // 4. Create with reuseExisting: true -> completes immediately (HTTP 200)
        $reusedRes = $this->postAudit($targetUrl, ['reuseExisting' => true]);
        $reusedRes->assertStatus(200);
        $this->assertSame('completed', $reusedRes->json('status'));
        $jobId2 = $reusedRes->json('jobId');
        $this->assertNotSame($jobId1, $jobId2);

        // Verify artifacts were copied
        $job2 = $this->store->getJob($jobId2);
        $this->assertFileExists($job2->artifactDir . DIRECTORY_SEPARATOR . 'reader.html');
        $this->assertFileExists($job2->artifactDir . DIRECTORY_SEPARATOR . 'report.pdf');

        // 5. Create with reuseExisting: false -> creates new queued job (HTTP 202)
        $newAuditRes = $this->postAudit($targetUrl, ['reuseExisting' => false]);
        $newAuditRes->assertStatus(202);
        $this->assertSame('queued', $newAuditRes->json('status'));
        $jobId3 = $newAuditRes->json('jobId');
        $this->assertNotSame($jobId1, $jobId3);
        $this->assertNotSame($jobId2, $jobId3);
    }

    /**
     * UC-USR-03: Cancel flow (while queued)
     * Cancel while queued.
     */
    public function test_UC_USR_03_cancel_flow_queued_job_cancelled_immediately(): void
    {
        $createRes = $this->postAudit('https://example.com/queued-cancel');
        $jobId = $createRes->json('jobId');
        $accessToken = $createRes->json('accessToken');

        // Cancel while still in queue
        $cancelRes = $this->delete("/audits/{$jobId}", [], [
            'Origin' => 'https://frontend.test',
            'Authorization' => "Bearer {$accessToken}",
        ]);
        $cancelRes->assertStatus(202);
        $this->assertSame('cancelled', $cancelRes->json('job.status'));
        $this->assertSame('Dibatalkan', $cancelRes->json('job.stage'));

        // Running worker does not process the cancelled job
        $processed = $this->worker->processNext();
        $this->assertFalse($processed);

        $job = $this->store->getJob($jobId);
        $this->assertSame('cancelled', $job?->status);
    }

    /**
     * UC-USR-04: Cancel flow (while running)
     * Cancel while running: worker stops child process and cleans artifact files.
     */
    public function test_UC_USR_04_cancel_flow_running_job_aborted_and_cleans_artifacts(): void
    {
        $createRes = $this->postAudit('https://example.com/running-cancel');
        $jobId = $createRes->json('jobId');
        $accessToken = $createRes->json('accessToken');

        $job = $this->store->claimNext();
        $this->assertNotNull($job);
        $this->assertSame('running', $job->status);

        // Pre-create partial artifacts
        mkdir($job->artifactDir, 0777, true);
        file_put_contents($job->artifactDir . DIRECTORY_SEPARATOR . 'partial.html', 'temp data');
        $this->assertFileExists($job->artifactDir . DIRECTORY_SEPARATOR . 'partial.html');

        // User issues DELETE while job is running
        $cancelRes = $this->delete("/audits/{$jobId}", [], [
            'Origin' => 'https://frontend.test',
            'Authorization' => "Bearer {$accessToken}",
        ]);
        $cancelRes->assertStatus(202);
        $this->assertSame('running', $cancelRes->json('job.status'));
        $this->assertTrue($cancelRes->json('job.canCancel'));

        // Worker executes run() and encounters cancelRequested = true
        $this->auditor->setHandler(function (AuditJob $job, ?callable $onProgress, ?callable $shouldCancel) {
            if ($shouldCancel !== null && $shouldCancel()) {
                throw new AuditCancelledException('Audit dibatalkan.');
            }
            return ['snapshot' => [], 'artifacts' => []];
        });

        $this->worker->run($job);

        // Verify status and artifact directory cleanup
        $updatedJob = $this->store->getJob($jobId);
        $this->assertSame('cancelled', $updatedJob?->status);
        $this->assertSame('Dibatalkan', $updatedJob?->stage);
        $this->assertDirectoryDoesNotExist($job->artifactDir);
    }

    /**
     * UC-USR-05: Queue flow
     * Two users submit at the same time; only one runs at a time, the second shows
     * the correct queuePosition, and both finish in order.
     */
    public function test_UC_USR_05_queue_flow_two_concurrent_users_run_fifo_with_queue_positions(): void
    {
        // User 1 submits
        $res1 = $this->postAudit('https://example.com/user1');
        $res1->assertStatus(202);
        $job1Id = $res1->json('jobId');
        $token1 = $res1->json('accessToken');

        // User 2 submits
        $res2 = $this->postAudit('https://example.com/user2');
        $res2->assertStatus(202);
        $job2Id = $res2->json('jobId');
        $token2 = $res2->json('accessToken');

        // When both are queued:
        // Job 1 has queue position 1
        $stat1Before = $this->get("/audits/{$job1Id}", ['Origin' => 'https://frontend.test', 'Authorization' => "Bearer {$token1}"]);
        $this->assertSame(1, $stat1Before->json('job.queuePosition'));

        // Job 2 has queue position 2
        $stat2Before = $this->get("/audits/{$job2Id}", ['Origin' => 'https://frontend.test', 'Authorization' => "Bearer {$token2}"]);
        $this->assertSame(2, $stat2Before->json('job.queuePosition'));

        // Job 1 starts running
        $claimed1 = $this->store->claimNext();
        $this->assertSame($job1Id, $claimed1?->id);

        // Job 1 is running -> queuePosition is null
        $stat1Running = $this->get("/audits/{$job1Id}", ['Origin' => 'https://frontend.test', 'Authorization' => "Bearer {$token1}"]);
        $this->assertSame('running', $stat1Running->json('job.status'));
        $this->assertNull($stat1Running->json('job.queuePosition'));

        // Job 2 is still queued -> now queuePosition is 1
        $stat2Queued = $this->get("/audits/{$job2Id}", ['Origin' => 'https://frontend.test', 'Authorization' => "Bearer {$token2}"]);
        $this->assertSame('queued', $stat2Queued->json('job.status'));
        $this->assertSame(1, $stat2Queued->json('job.queuePosition'));

        // Job 1 completes
        $this->worker->run($claimed1);
        $this->assertSame('completed', $this->store->getJob($job1Id)?->status);

        // Now Job 2 is claimed and processed
        $claimed2 = $this->store->claimNext();
        $this->assertSame($job2Id, $claimed2?->id);
        $this->worker->run($claimed2);
        $this->assertSame('completed', $this->store->getJob($job2Id)?->status);

        // Both completed in FIFO order
        $this->assertTrue($this->store->getJob($job1Id)->finishedAt <= $this->store->getJob($job2Id)->finishedAt);
    }

    /**
     * UC-USR-06: Retry flow
     * A transient failure (browser-failed, and so on) is retried once and then succeeds.
     */
    public function test_UC_USR_06_retry_flow_transient_failure_retried_once_then_succeeds(): void
    {
        $createRes = $this->postAudit('https://example.com/transient-retry');
        $jobId = $createRes->json('jobId');
        $token = $createRes->json('accessToken');

        $attemptsSeen = 0;
        $this->auditor->setHandler(function (AuditJob $job) use (&$attemptsSeen) {
            $attemptsSeen++;
            if ($attemptsSeen === 1) {
                throw new AuditFailedException('browser-failed', null, 'Chromium process exited unexpectedly');
            }

            mkdir($job->artifactDir, 0777, true);
            file_put_contents($job->artifactDir . DIRECTORY_SEPARATOR . 'reader.html', '<title>Success</title>');

            return [
                'snapshot' => ['summary' => ['beforeTotal' => 1]],
                'artifacts' => ['reader' => 'reader.html'],
            ];
        });

        // First attempt -> caught as transient, reset to queued
        $processed1 = $this->worker->processNext();
        $this->assertTrue($processed1);

        $pollRetry = $this->get("/audits/{$jobId}", [
            'Origin' => 'https://frontend.test',
            'Authorization' => "Bearer {$token}",
        ]);
        $pollRetry->assertStatus(200);
        $this->assertSame('queued', $pollRetry->json('job.status'));
        $this->assertSame(1, $pollRetry->json('job.attempts'));
        $this->assertSame('Menunggu percobaan ulang', $pollRetry->json('job.stage'));

        // Second attempt -> succeeds
        $processed2 = $this->worker->processNext();
        $this->assertTrue($processed2);

        $pollSuccess = $this->get("/audits/{$jobId}", [
            'Origin' => 'https://frontend.test',
            'Authorization' => "Bearer {$token}",
        ]);
        $pollSuccess->assertStatus(200);
        $this->assertSame('completed', $pollSuccess->json('job.status'));
        $this->assertSame(2, $pollSuccess->json('job.attempts'));
        $this->assertSame(100, $pollSuccess->json('job.progress'));
    }

    /**
     * UC-USR-07: Permanent failure flow
     * A permanent failure ends as failed with the correct Indonesian error message.
     */
    public function test_UC_USR_07_permanent_failure_terminates_immediately_with_indonesian_message(): void
    {
        $createRes = $this->postAudit('https://example.com/blocked-by-robots');
        $jobId = $createRes->json('jobId');
        $token = $createRes->json('accessToken');

        $this->auditor->setHandler(function () {
            throw new AuditFailedException(
                'robots-disallowed',
                null,
                'Aturan robots.txt situs tersebut tidak mengizinkan audit otomatis.'
            );
        });

        $processed = $this->worker->processNext();
        $this->assertTrue($processed);

        $pollFailed = $this->get("/audits/{$jobId}", [
            'Origin' => 'https://frontend.test',
            'Authorization' => "Bearer {$token}",
        ]);
        $pollFailed->assertStatus(200);
        $this->assertSame('failed', $pollFailed->json('job.status'));
        $this->assertSame('robots-disallowed', $pollFailed->json('job.error.code'));
        $this->assertSame(
            'Aturan robots.txt situs tersebut tidak mengizinkan audit otomatis.',
            $pollFailed->json('job.error.message')
        );

        // Attempting to run again does not process this job (it is already failed)
        $this->assertFalse($this->worker->processNext());
    }

    /**
     * UC-USR-08: Expiry flow
     * After RESULT_RETENTION_MS the result and artifacts return the expired response
     * and audit:cleanup removes the DB row and files.
     */
    public function test_UC_USR_08_expiry_flow_returns_410_and_artisan_cleanup_removes_files_and_db(): void
    {
        $createRes = $this->postAudit('https://example.com/expiry-test');
        $jobId = $createRes->json('jobId');
        $token = $createRes->json('accessToken');

        $this->worker->processNext();
        $job = $this->store->getJob($jobId);
        $this->assertSame('completed', $job?->status);

        // Retrieve result and shareToken
        $resultRes = $this->get("/audits/{$jobId}/result", [
            'Origin' => 'https://frontend.test',
            'Authorization' => "Bearer {$token}",
        ]);
        $resultRes->assertStatus(200);
        $readerLink = $resultRes->json('links.reader');

        // Artifact is accessible while valid
        $this->get($readerLink, ['Origin' => 'https://frontend.test'])->assertStatus(200);

        // Fast-forward expiration in DB: expiresAt set in the past
        $pastTime = (int) (microtime(true) * 1000) - 10000;
        $pdo = new \PDO("sqlite:{$this->dbPath}");
        $stmt = $pdo->prepare('UPDATE audit_jobs SET expires_at = ? WHERE id = ?');
        $stmt->execute([$pastTime, $jobId]);

        // Accessing artifact after expiry returns HTTP 410
        $expiredRes = $this->get($readerLink, ['Origin' => 'https://frontend.test']);
        $expiredRes->assertStatus(410);
        $expiredRes->assertJson([
            'error' => 'expired',
            'message' => 'Artefak sudah kedaluwarsa.',
        ]);

        // Run artisan audit:cleanup
        $exitCode = Artisan::call('audit:cleanup');
        $this->assertSame(0, $exitCode);

        // DB row removed and disk directory deleted
        $this->assertNull($this->store->getJob($jobId));
        $this->assertDirectoryDoesNotExist($job->artifactDir);
    }

    /**
     * UC-USR-09: Crash recovery
     * A job left running after a crash is recovered when the worker restarts.
     * Tests both without checkpoint (to queued, progress 0) and with checkpoint (to queued, progress 90).
     */
    public function test_UC_USR_09_crash_recovery_recovers_interrupted_jobs_on_worker_startup(): void
    {
        $now = (int) (microtime(true) * 1000);

        // Case A: Running job without checkpoint
        $jobNoCk = $this->store->createJob([
            'id' => 'aud_crash_no_checkpoint',
            'tokenHash' => SecurityService::digest('tok1', $this->config->secret),
            'shareTokenHash' => SecurityService::digest('sh1', $this->config->secret),
            'ipHash' => SecurityService::digest('127.0.0.1', $this->config->secret),
            'targetUrl' => 'https://example.com/crash1',
            'createdAt' => $now,
            'expiresAt' => $now + 60000,
            'artifactDir' => $this->artifactsDir . DIRECTORY_SEPARATOR . 'aud_crash_no_checkpoint',
        ]);
        $this->store->claimNext(); // now status = running, attempts = 1

        // Case B: Running job with checkpoint
        $jobWithCk = $this->store->createJob([
            'id' => 'aud_crash_with_checkpoint',
            'tokenHash' => SecurityService::digest('tok2', $this->config->secret),
            'shareTokenHash' => SecurityService::digest('sh2', $this->config->secret),
            'ipHash' => SecurityService::digest('127.0.0.1', $this->config->secret),
            'targetUrl' => 'https://example.com/crash2',
            'createdAt' => $now,
            'expiresAt' => $now + 60000,
            'artifactDir' => $this->artifactsDir . DIRECTORY_SEPARATOR . 'aud_crash_with_checkpoint',
        ]);
        $this->store->claimNext(); // now status = running, attempts = 1

        // Place snapshot.json checkpoint for Case B
        mkdir($jobWithCk->artifactDir, 0777, true);
        file_put_contents($jobWithCk->artifactDir . DIRECTORY_SEPARATOR . 'snapshot.json', json_encode(['score' => 95]));

        // Worker simulates restart: calls recover()
        $recoveredCount = $this->worker->recover();
        $this->assertSame(2, $recoveredCount);

        // Verify Case A recovered to queued at 0%
        $recoveredA = $this->store->getJob('aud_crash_no_checkpoint');
        $this->assertSame('queued', $recoveredA?->status);
        $this->assertSame(0, $recoveredA?->progress);
        $this->assertSame('Dipulihkan setelah server restart', $recoveredA?->stage);

        // Verify Case B recovered to queued at 90%
        $recoveredB = $this->store->getJob('aud_crash_with_checkpoint');
        $this->assertSame('queued', $recoveredB?->status);
        $this->assertSame(90, $recoveredB?->progress);
        $this->assertSame('Melanjutkan finalisasi dari checkpoint tersimpan', $recoveredB?->stage);

        // Case C: If interrupted twice (attempts >= 2), worker marks it failed with server-restarted
        $this->store->claimNext(); // attempts becomes 2
        $this->worker->recover();
        $failedC = $this->store->getJob('aud_crash_no_checkpoint');
        $this->assertSame('failed', $failedC?->status);
        $this->assertSame('server-restarted', $failedC?->errorCode);
        $this->assertSame('Audit terputus dua kali karena server restart.', $failedC?->errorMessage);
    }

    /**
     * UC-USR-10: Frontend compatibility
     * Replays the exact request sequence src/lib/audit-api.ts makes and asserts
     * every field the frontend reads exists with the right type.
     */
    public function test_UC_USR_10_frontend_compatibility_replays_exact_audit_api_client_contract(): void
    {
        $testUrl = 'https://aksaranetra-contract.test/page';

        // 1. Frontend calls checkAuditCache(url)
        $cacheRes = $this->get('/audits/cache?url=' . urlencode($testUrl), [
            'Origin' => 'https://frontend.test',
            'Accept' => 'application/json',
        ]);
        $cacheRes->assertStatus(200);
        $cacheData = $cacheRes->json();
        $this->assertIsBool($cacheData['available']);
        $this->assertNull($cacheData['capturedAt']);
        $this->assertNull($cacheData['expiresAt']);

        // 2. Frontend calls createAudit(url, false)
        $createRes = $this->postJson('/audits', ['url' => $testUrl, 'reuseExisting' => false], [
            'Origin' => 'https://frontend.test',
            'Accept' => 'application/json',
        ]);
        $createRes->assertStatus(202);
        $createData = $createRes->json();

        $this->assertIsString($createData['jobId']);
        $this->assertMatchesRegularExpression('/^aud_/', $createData['jobId']);
        $this->assertIsString($createData['accessToken']);
        $this->assertGreaterThan(30, strlen($createData['accessToken']));
        $this->assertContains($createData['status'], ['queued', 'running', 'completed', 'failed', 'cancelled']);
        $this->assertIsString($createData['expiresAt']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/', $createData['expiresAt']);

        $jobId = $createData['jobId'];
        $token = $createData['accessToken'];

        // 3. Frontend calls getAudit(jobId, token)
        $pollRes = $this->get('/audits/' . urlencode($jobId), [
            'Origin' => 'https://frontend.test',
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ]);
        $pollRes->assertStatus(200);
        $jobData = $pollRes->json('job');

        // Validate all 12 properties from interface AuditJob in audit-api.ts
        $this->assertIsString($jobData['jobId']);
        $this->assertSame($testUrl, $jobData['url']);
        $this->assertContains($jobData['status'], ['queued', 'running', 'completed', 'failed', 'cancelled']);
        $this->assertIsString($jobData['stage']);
        $this->assertIsInt($jobData['progress']);
        $this->assertTrue(is_null($jobData['queuePosition']) || is_int($jobData['queuePosition']));
        $this->assertIsInt($jobData['attempts']);
        $this->assertIsBool($jobData['canCancel']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/', $jobData['createdAt']);
        $this->assertNull($jobData['startedAt']);
        $this->assertNull($jobData['finishedAt']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/', $jobData['expiresAt']);
        $this->assertNull($jobData['error']);

        // 4. Complete the job and verify getAuditResult(jobId, token)
        $this->auditor->setHandler(function (AuditJob $job) {
            mkdir($job->artifactDir, 0777, true);
            file_put_contents($job->artifactDir . DIRECTORY_SEPARATOR . 'reader.html', '<!doctype html><title>Reader</title>');
            file_put_contents($job->artifactDir . DIRECTORY_SEPARATOR . 'patched.html', '<!doctype html><title>Patched</title>');
            file_put_contents($job->artifactDir . DIRECTORY_SEPARATOR . 'report.pdf', '%PDF-test');
            file_put_contents($job->artifactDir . DIRECTORY_SEPARATOR . 'after.png', 'png');

            return [
                'snapshot' => [
                    'engineVersion' => '0.3.0',
                    'capturedAt' => '2026-10-20T12:00:00.000Z',
                    'disclaimer' => 'AksaraNetra automated accessibility report',
                    'source' => [
                        'requestedUrl' => 'https://aksaranetra-contract.test/page',
                        'finalUrl' => 'https://aksaranetra-contract.test/page',
                        'title' => 'Page Title',
                        'status' => 200,
                        'siteLabel' => 'Testing Site',
                    ],
                    'summary' => [
                        'beforeTotal' => 10,
                        'afterTotal' => 2,
                        'reduction' => 8,
                        'reductionPercent' => 80,
                        'strictImprovement' => true,
                        'noRegression' => true,
                    ],
                    'rules' => [
                        'image-alt' => ['before' => 5, 'after' => 0, 'reduction' => 5],
                    ],
                    'counts' => [
                        'fixed' => 8,
                        'review' => 2,
                        'skipped' => 0,
                        'rolledBack' => 0,
                        'verifiedOverrides' => 0,
                    ],
                    'warnings' => [
                        'rolledBack' => false,
                        'wcagRegressionClean' => true,
                        'worsenedRules' => [],
                        'duplicateLabels' => [],
                        'staleOverrides' => [],
                        'rolledBackRecords' => [],
                    ],
                ],
                'artifacts' => [
                    'reader' => 'reader.html',
                    'patched' => 'patched.html',
                    'reportPdf' => 'report.pdf',
                    'screenshot' => 'after.png',
                ],
            ];
        });

        $this->worker->processNext();

        $resultRes = $this->get('/audits/' . urlencode($jobId) . '/result', [
            'Origin' => 'https://frontend.test',
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ]);
        $resultRes->assertStatus(200);
        $resultData = $resultRes->json();

        // Validate AuditResult interface
        $this->assertArrayHasKey('job', $resultData);
        $this->assertArrayHasKey('result', $resultData);
        $this->assertArrayHasKey('links', $resultData);

        // Validate links
        $this->assertIsString($resultData['links']['reader']);
        $this->assertIsString($resultData['links']['patched']);
        $this->assertIsString($resultData['links']['reportPdf']);
        $this->assertIsString($resultData['links']['screenshot']);

        // Validate snapshot
        $snapshot = $resultData['result']['snapshot'];
        $this->assertSame('0.3.0', $snapshot['engineVersion']);
        $this->assertIsString($snapshot['capturedAt']);
        $this->assertIsString($snapshot['disclaimer']);
        $this->assertSame('https://aksaranetra-contract.test/page', $snapshot['source']['requestedUrl']);
        $this->assertSame(200, $snapshot['source']['status']);
        $this->assertSame(10, $snapshot['summary']['beforeTotal']);
        $this->assertSame(2, $snapshot['summary']['afterTotal']);
        $this->assertTrue($snapshot['summary']['strictImprovement']);
        $this->assertTrue($snapshot['summary']['noRegression']);
        $this->assertSame(8, $snapshot['counts']['fixed']);
        $this->assertTrue($snapshot['warnings']['wcagRegressionClean']);
    }
}
