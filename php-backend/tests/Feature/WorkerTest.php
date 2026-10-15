<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\AuditCancelledException;
use App\Exceptions\AuditFailedException;
use App\Models\AuditJob;
use App\Services\AuditWorker;
use App\Services\JobStore;
use PHPUnit\Framework\TestCase;
use Tests\Fakes\FakeAuditor;

class WorkerTest extends TestCase
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

    private function createFixture(): array
    {
        $root = $this->createTempDir('aksara-worker-');
        $store = new JobStore($root . DIRECTORY_SEPARATOR . 'jobs.sqlite');

        $add = function (string $id) use ($store, $root): AuditJob {
            $now = (int) (microtime(true) * 1000);

            return $store->createJob([
                'id' => $id,
                'tokenHash' => str_repeat('a', 64),
                'shareTokenHash' => str_repeat('b', 64),
                'ipHash' => str_repeat('c', 64),
                'targetUrl' => 'https://example.com/',
                'createdAt' => $now,
                'expiresAt' => $now + 60_000,
                'artifactDir' => $root . DIRECTORY_SEPARATOR . $id,
            ]);
        };

        return [$root, $store, $add];
    }

    public function test_runs_only_one_audit_at_a_time(): void
    {
        [$root, $store, $add] = $this->createFixture();
        $add('one');
        $add('two');

        $active = 0;
        $maximum = 0;

        $fakeAuditor = new FakeAuditor();
        $fakeAuditor->setHandler(function (AuditJob $job, ?callable $onProgress) use (&$active, &$maximum) {
            $active++;
            $maximum = max($maximum, $active);
            if ($onProgress !== null) {
                $onProgress(['progress' => 50, 'stage' => 'Sedang audit']);
            }
            usleep(30_000); // 30ms
            $active--;

            return [
                'snapshot' => ['ok' => true],
                'artifacts' => [
                    'reader' => 'reader.html',
                    'patched' => 'patched.html',
                    'reportPdf' => 'report.pdf',
                    'screenshot' => 'after.png',
                ],
            ];
        });

        $worker = new AuditWorker(
            store: $store,
            auditor: $fakeAuditor,
            timeoutMs: 1000,
            concurrency: 1
        );

        $worker->drain();

        $this->assertSame(1, $maximum);
        $this->assertSame('completed', $store->getJob('one')?->status);
        $this->assertSame(100, $store->getJob('one')?->progress);
        $this->assertSame('completed', $store->getJob('two')?->status);
        $this->assertSame(100, $store->getJob('two')?->progress);
    }

    public function test_retries_one_transient_failure_and_then_succeeds(): void
    {
        [$root, $store, $add] = $this->createFixture();
        $add('retry');

        $calls = 0;
        $fakeAuditor = new FakeAuditor();
        $fakeAuditor->setHandler(function (AuditJob $job) use (&$calls) {
            $calls++;
            if ($calls === 1) {
                throw new AuditFailedException('browser-failed', null, 'browser closed');
            }

            return [
                'snapshot' => ['ok' => true],
                'artifacts' => [
                    'reader' => 'reader.html',
                    'patched' => 'patched.html',
                    'reportPdf' => 'report.pdf',
                    'screenshot' => 'after.png',
                ],
            ];
        });

        $worker = new AuditWorker(
            store: $store,
            auditor: $fakeAuditor,
            timeoutMs: 1000,
            concurrency: 1
        );

        // First run fails with browser-failed and resets to queued
        $processedFirst = $worker->processNext();
        $this->assertTrue($processedFirst);
        $this->assertSame('queued', $store->getJob('retry')?->status);
        $this->assertSame(1, $store->getJob('retry')?->attempts);

        // Second run succeeds
        $processedSecond = $worker->processNext();
        $this->assertTrue($processedSecond);

        $this->assertSame(2, $calls);
        $this->assertSame('completed', $store->getJob('retry')?->status);
        $this->assertSame(2, $store->getJob('retry')?->attempts);
    }

    public function test_cancels_a_running_audit_and_removes_partial_artifacts(): void
    {
        [$root, $store, $add] = $this->createFixture();
        $add('cancel');

        $artifactDir = $root . DIRECTORY_SEPARATOR . 'cancel';
        mkdir($artifactDir, 0777, true);
        file_put_contents($artifactDir . DIRECTORY_SEPARATOR . 'partial.txt', 'partial content');

        $worker = null;
        $fakeAuditor = new FakeAuditor();
        $fakeAuditor->setHandler(function (AuditJob $job, ?callable $onProgress, ?callable $shouldCancel) use (&$worker) {
            // Simulate user requesting cancellation mid-audit
            $worker->cancel($job->id);

            if ($shouldCancel !== null && $shouldCancel()) {
                throw new AuditCancelledException('Audit dibatalkan.');
            }

            return ['snapshot' => ['ok' => true], 'artifacts' => []];
        });

        $worker = new AuditWorker(
            store: $store,
            auditor: $fakeAuditor,
            timeoutMs: 1000,
            concurrency: 1
        );

        $worker->processNext();

        $this->assertSame('cancelled', $store->getJob('cancel')?->status);
        $this->assertFalse(is_dir($artifactDir));
    }

    public function test_fails_with_audit_timeout_and_removes_partial_artifacts(): void
    {
        [$root, $store, $add] = $this->createFixture();
        $add('timeout_job');

        $artifactDir = $root . DIRECTORY_SEPARATOR . 'timeout_job';
        mkdir($artifactDir, 0777, true);
        file_put_contents($artifactDir . DIRECTORY_SEPARATOR . 'partial.txt', 'timeout partial');

        $fakeAuditor = new FakeAuditor();
        $fakeAuditor->setHandler(function (AuditJob $job, ?callable $onProgress, ?callable $shouldCancel) {
            // Sleep past timeoutMs (50ms)
            usleep(80_000);
            if ($shouldCancel !== null && $shouldCancel()) {
                throw new \App\Exceptions\AuditTimeoutException('Timeout');
            }

            return ['snapshot' => ['ok' => true], 'artifacts' => []];
        });

        $worker = new AuditWorker(
            store: $store,
            auditor: $fakeAuditor,
            timeoutMs: 50,
            concurrency: 1
        );

        $worker->processNext();

        $job = $store->getJob('timeout_job');
        $this->assertSame('failed', $job?->status);
        $this->assertSame('audit-timeout', $job?->errorCode);
        $this->assertStringContainsString('Audit melewati batas', $job?->errorMessage ?? '');
        $this->assertFalse(is_dir($artifactDir));
    }

    public function test_fails_permanently_on_second_transient_failure(): void
    {
        [$root, $store, $add] = $this->createFixture();
        $add('fail_twice');

        $calls = 0;
        $fakeAuditor = new FakeAuditor();
        $fakeAuditor->setHandler(function () use (&$calls) {
            $calls++;
            throw new AuditFailedException('browser-failed', null, 'Chromium crashed');
        });

        $worker = new AuditWorker(
            store: $store,
            auditor: $fakeAuditor,
            timeoutMs: 1000,
            concurrency: 1
        );

        // First attempt -> queued for retry
        $worker->processNext();
        $this->assertSame('queued', $store->getJob('fail_twice')?->status);
        $this->assertSame(1, $store->getJob('fail_twice')?->attempts);

        // Second attempt -> fails permanently
        $worker->processNext();
        $this->assertSame('failed', $store->getJob('fail_twice')?->status);
        $this->assertSame('browser-failed', $store->getJob('fail_twice')?->errorCode);
        $this->assertSame(2, $calls);
    }

    public function test_fails_immediately_on_non_transient_error(): void
    {
        [$root, $store, $add] = $this->createFixture();
        $add('non_transient');

        $calls = 0;
        $fakeAuditor = new FakeAuditor();
        $fakeAuditor->setHandler(function () use (&$calls) {
            $calls++;
            throw new AuditFailedException('robots-disallowed', null, 'Robots denied access');
        });

        $worker = new AuditWorker(
            store: $store,
            auditor: $fakeAuditor,
            timeoutMs: 1000,
            concurrency: 1
        );

        $worker->processNext();

        $this->assertSame(1, $calls);
        $this->assertSame('failed', $store->getJob('non_transient')?->status);
        $this->assertSame('robots-disallowed', $store->getJob('non_transient')?->errorCode);
    }
}
