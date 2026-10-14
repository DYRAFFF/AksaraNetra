<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\JobStore;
use PHPUnit\Framework\TestCase;

class StoreTest extends TestCase
{
    private array $tempDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            if (is_dir($dir)) {
                $this->removeDirectoryRecursive($dir);
            }
        }
        parent::tearDown();
    }

    private function createTempDir(string $prefix): string
    {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $prefix . uniqid();
        mkdir($dir, 0777, true);
        $this->tempDirs[] = $dir;
        return $dir;
    }

    private function removeDirectoryRecursive(string $dir): void
    {
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

    private function createTestJob(JobStore $store, string $root, string $id, int $now, ?int $expiresAt = null)
    {
        return $store->createJob([
            'id' => $id,
            'tokenHash' => str_repeat('a', 64),
            'shareTokenHash' => str_repeat('b', 64),
            'ipHash' => str_repeat('c', 64),
            'targetUrl' => 'https://example.com/',
            'createdAt' => $now,
            'expiresAt' => $expiresAt ?? ($now + 10000),
            'artifactDir' => $root . DIRECTORY_SEPARATOR . $id,
        ]);
    }

    /**
     * Port of store.test.mjs: "persists queue state and recovers interrupted jobs once"
     */
    public function test_persists_queue_state_and_recovers_interrupted_jobs_once(): void
    {
        $root = $this->createTempDir('aksara-store-');
        $db = $root . DIRECTORY_SEPARATOR . 'jobs.sqlite';
        $now = 1000;

        $store = new JobStore(['databasePath' => $db, 'now' => fn(): int => $now]);
        $this->createTestJob($store, $root, 'job-1', $now);

        $claimed = $store->claimNext();
        $this->assertNotNull($claimed);
        $this->assertSame(1, $claimed->attempts);
        $this->assertSame('running', $claimed->status);
        $store->close();

        // Restart 1: Recover interrupted job (attempts < 2, no checkpoint -> recovers to queued, progress 0)
        $now += 100;
        $store = new JobStore(['databasePath' => $db, 'now' => fn(): int => $now]);
        $jobAfterRestart1 = $store->getJob('job-1');
        $this->assertNotNull($jobAfterRestart1);
        $this->assertSame('queued', $jobAfterRestart1->status);
        $this->assertSame(0, $jobAfterRestart1->progress);
        $this->assertSame('Dipulihkan setelah server restart', $jobAfterRestart1->stage);

        $claimed2 = $store->claimNext();
        $this->assertNotNull($claimed2);
        $this->assertSame(2, $claimed2->attempts);
        $this->assertSame('running', $claimed2->status);
        $store->close();

        // Restart 2: Attempts >= 2 -> recovers to failed with errorCode 'server-restarted'
        $now += 100;
        $store = new JobStore(['databasePath' => $db, 'now' => fn(): int => $now]);
        $jobAfterRestart2 = $store->getJob('job-1');
        $this->assertNotNull($jobAfterRestart2);
        $this->assertSame('failed', $jobAfterRestart2->status);
        $this->assertSame('server-restarted', $jobAfterRestart2->errorCode);
        $this->assertSame('Audit terputus dua kali karena server restart.', $jobAfterRestart2->errorMessage);
        $store->close();
    }

    /**
     * Port of store.test.mjs: "returns expired artifact folders before deleting rows"
     */
    public function test_returns_expired_artifact_folders_before_deleting_rows(): void
    {
        $root = $this->createTempDir('aksara-expiry-');
        $db = $root . DIRECTORY_SEPARATOR . 'jobs.sqlite';

        $store = new JobStore(['databasePath' => $db, 'now' => fn(): int => 5000]);
        $this->createTestJob($store, $root, 'expired', 1000, 2000);

        $rows = $store->deleteExpired(5000);
        $this->assertCount(1, $rows);
        $this->assertSame('expired', $rows[0]['id']);
        $this->assertNull($store->getJob('expired'));
        $store->close();
    }

    /**
     * Test checkpoint recovery when snapshot.json exists in artifactDir
     */
    public function test_recovers_interrupted_job_with_checkpoint_to_progress_90(): void
    {
        $root = $this->createTempDir('aksara-checkpoint-');
        $db = $root . DIRECTORY_SEPARATOR . 'jobs.sqlite';
        $now = 1000;

        $store = new JobStore(['databasePath' => $db, 'now' => fn(): int => $now]);
        $this->createTestJob($store, $root, 'job-checkpoint', $now);
        $store->claimNext();

        // Create snapshot.json checkpoint inside artifact directory
        $artifactDir = $root . DIRECTORY_SEPARATOR . 'job-checkpoint';
        mkdir($artifactDir, 0777, true);
        file_put_contents($artifactDir . DIRECTORY_SEPARATOR . 'snapshot.json', json_encode(['checkpoint' => true]));

        $store->close();

        // Server restarts: detected checkpoint
        $now += 100;
        $store = new JobStore(['databasePath' => $db, 'now' => fn(): int => $now]);
        $job = $store->getJob('job-checkpoint');

        $this->assertNotNull($job);
        $this->assertSame('queued', $job->status);
        $this->assertSame(90, $job->progress);
        $this->assertSame('Melanjutkan finalisasi dari checkpoint tersimpan', $job->stage);
        $store->close();
    }

    /**
     * Test job store operations: countRecentByIp, queuePosition, updateProgress, complete, cancel, fail, retry, findReusable
     */
    public function test_complete_job_lifecycle_and_helpers(): void
    {
        $root = $this->createTempDir('aksara-lifecycle-');
        $db = $root . DIRECTORY_SEPARATOR . 'jobs.sqlite';
        $now = 1000;

        $store = new JobStore(['databasePath' => $db, 'now' => fn(): int => $now]);
        $ipHash = str_repeat('c', 64);

        // 1. Create job
        $job = $store->createJob([
            'id' => 'job-lifecycle',
            'tokenHash' => str_repeat('a', 64),
            'shareTokenHash' => str_repeat('b', 64),
            'ipHash' => $ipHash,
            'targetUrl' => 'https://example.com/page',
            'createdAt' => $now,
            'expiresAt' => $now + 50000,
            'artifactDir' => $root . DIRECTORY_SEPARATOR . 'job-lifecycle',
        ]);
        $this->assertSame('queued', $job->status);
        $this->assertSame(1, $store->countRecentByIp($ipHash, $now - 100));
        $this->assertSame(1, $store->queuePosition('job-lifecycle'));

        // 2. Claim next
        $claimed = $store->claimNext();
        $this->assertNotNull($claimed);
        $this->assertSame('running', $claimed->status);
        $this->assertSame(5, $claimed->progress);
        $this->assertNull($store->queuePosition('job-lifecycle')); // Null once not queued

        // 3. Update progress (never decreases)
        $store->updateProgress('job-lifecycle', ['stage' => 'Auditing DOM', 'progress' => 50]);
        $this->assertSame(50, $store->getJob('job-lifecycle')->progress);
        $this->assertSame('Auditing DOM', $store->getJob('job-lifecycle')->stage);

        // Attempt lower progress -> clamped to MAX (remains 50)
        $store->updateProgress('job-lifecycle', ['stage' => 'Still auditing', 'progress' => 30]);
        $this->assertSame(50, $store->getJob('job-lifecycle')->progress);
        $this->assertSame('Still auditing', $store->getJob('job-lifecycle')->stage);

        // Attempt progress >= 100 -> clamped to 99 for updateProgress
        $store->updateProgress('job-lifecycle', ['stage' => 'Finalizing', 'progress' => 150]);
        $this->assertSame(99, $store->getJob('job-lifecycle')->progress);

        // 4. Complete
        $resultData = ['summary' => ['issues' => 2]];
        $store->complete('job-lifecycle', $resultData);
        $completed = $store->getJob('job-lifecycle');
        $this->assertSame('completed', $completed->status);
        $this->assertSame(100, $completed->progress);
        $this->assertSame('Audit selesai', $completed->stage);
        $this->assertSame($resultData, $completed->result);

        // 5. findReusable returns completed job
        $reusable = $store->findReusable('https://example.com/page', $now);
        $this->assertNotNull($reusable);
        $this->assertSame('job-lifecycle', $reusable->id);

        // Expired check for findReusable
        $this->assertNull($store->findReusable('https://example.com/page', $now + 60000));
        $this->assertNull($store->findReusable('https://other.com/'));

        // 6. Test retry on another job
        $job2 = $this->createTestJob($store, $root, 'job-retry', $now + 10);
        $claimed2 = $store->claimNext();
        $this->assertSame('running', $claimed2->status);
        $store->retry('job-retry', ['code' => 'browser-failed', 'message' => 'Chromium crash']);
        $retried = $store->getJob('job-retry');
        $this->assertSame('queued', $retried->status);
        $this->assertSame('Menunggu percobaan ulang', $retried->stage);
        $this->assertSame('browser-failed', $retried->errorCode);

        // 7. Test requestCancel on queued job -> cancels immediately
        $cancelledQueued = $store->requestCancel('job-retry');
        $this->assertSame('cancelled', $cancelledQueued->status);
        $this->assertSame('Dibatalkan', $cancelledQueued->stage);

        // 8. Test requestCancel on running job -> sets cancel_requested=1
        $job3 = $this->createTestJob($store, $root, 'job-running-cancel', $now + 20);
        $claimed3 = $store->claimNext();
        $this->assertSame('running', $claimed3->status);
        $cancelledRunning = $store->requestCancel('job-running-cancel');
        $this->assertSame('running', $cancelledRunning->status);
        $this->assertTrue($cancelledRunning->cancelRequested);
        $this->assertSame('Membatalkan audit', $cancelledRunning->stage);

        // Now finalize running cancellation
        $store->cancelRunning('job-running-cancel');
        $this->assertSame('cancelled', $store->getJob('job-running-cancel')->status);
        $this->assertSame('Dibatalkan', $store->getJob('job-running-cancel')->stage);

        // 9. Test fail
        $job4 = $this->createTestJob($store, $root, 'job-fail', $now + 30);
        $store->claimNext();
        $store->fail('job-fail', ['code' => 'audit-timeout', 'message' => 'Waktu habis']);
        $failed = $store->getJob('job-fail');
        $this->assertSame('failed', $failed->status);
        $this->assertSame('audit-timeout', $failed->errorCode);
        $this->assertSame('Waktu habis', $failed->errorMessage);

        // 10. Test completeQueued
        $job5 = $this->createTestJob($store, $root, 'job-queued-complete', $now + 40);
        $store->completeQueued('job-queued-complete', ['cached' => true]);
        $cachedJob = $store->getJob('job-queued-complete');
        $this->assertSame('completed', $cachedJob->status);
        $this->assertSame('Menggunakan hasil tersimpan', $cachedJob->stage);
        $this->assertSame(['cached' => true], $cachedJob->result);

        $store->close();
    }
}
