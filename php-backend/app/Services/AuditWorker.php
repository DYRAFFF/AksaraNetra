<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\AuditCancelledException;
use App\Exceptions\AuditFailedException;
use App\Exceptions\AuditTimeoutException;
use App\Models\AuditJob;
use Throwable;

class AuditWorker implements AuditWorkerInterface
{
    public const TRANSIENT_CODES = [
        'dns-lookup-failed',
        'robots-fetch-failed',
        'browser-failed',
        'audit-failed',
    ];

    private bool $stopped = false;
    private ?string $activeJobId = null;
    private bool $activeJobCancelled = false;

    public function __construct(
        private readonly JobStore $store,
        private readonly AuditorInterface $auditor,
        private readonly int $timeoutMs = 180_000,
        private readonly int $concurrency = 1
    ) {}

    public function kick(): void
    {
        // For long-running worker loops or external triggers
    }

    public function stop(): void
    {
        $this->stopped = true;
        $this->activeJobCancelled = true;
    }

    public function isStopped(): bool
    {
        return $this->stopped;
    }

    public function cancel(string $id): ?AuditJob
    {
        $job = $this->store->requestCancel($id);
        if ($this->activeJobId === $id) {
            $this->activeJobCancelled = true;
        }

        return $job;
    }

    public function recover(): int
    {
        return $this->store->recoverInterrupted();
    }

    public function processNext(): bool
    {
        if ($this->stopped) {
            return false;
        }

        $job = $this->store->claimNext();
        if ($job === null) {
            return false;
        }

        $this->run($job);

        return true;
    }

    public function drain(): int
    {
        $count = 0;
        while (!$this->stopped && $this->processNext()) {
            $count++;
        }

        return $count;
    }

    public function run(AuditJob $job): void
    {
        $this->activeJobId = $job->id;
        $this->activeJobCancelled = false;
        $startTime = microtime(true);
        $timedOut = false;

        $shouldCancel = function () use ($job, $startTime, &$timedOut): bool {
            if ($this->stopped) {
                return true;
            }
            if ($this->activeJobCancelled) {
                return true;
            }
            $elapsedMs = (microtime(true) - $startTime) * 1000;
            if ($elapsedMs >= $this->timeoutMs) {
                $timedOut = true;

                return true;
            }
            $current = $this->store->getJob($job->id);
            if ($current !== null && $current->cancelRequested) {
                $this->activeJobCancelled = true;

                return true;
            }

            return false;
        };

        $onProgress = function (array $update) use ($job): void {
            $this->store->updateProgress($job->id, $update);
        };

        try {
            $result = $this->auditor->audit($job, $onProgress, $shouldCancel);

            $current = $this->store->getJob($job->id);
            if ($this->stopped && $current?->status === 'running') {
                return;
            }

            if ($timedOut) {
                $this->store->fail($job->id, [
                    'code' => 'audit-timeout',
                    'message' => 'Audit melewati batas ' . (int) round($this->timeoutMs / 1000) . ' detik.',
                ]);
                $this->deleteArtifactDir($job->artifactDir);
            } elseif ($this->activeJobCancelled || ($current !== null && $current->cancelRequested)) {
                $this->store->cancelRunning($job->id);
                $this->deleteArtifactDir($job->artifactDir);
            } else {
                $this->store->complete($job->id, $result);
            }
        } catch (Throwable $error) {
            $current = $this->store->getJob($job->id);
            if ($this->stopped && $current?->status === 'running') {
                return;
            }

            if ($timedOut || ($error instanceof AuditTimeoutException)) {
                $this->store->fail($job->id, [
                    'code' => 'audit-timeout',
                    'message' => 'Audit melewati batas ' . (int) round($this->timeoutMs / 1000) . ' detik.',
                ]);
                $this->deleteArtifactDir($job->artifactDir);
            } elseif ($this->activeJobCancelled || ($current !== null && $current->cancelRequested) || ($error instanceof AuditCancelledException)) {
                $this->store->cancelRunning($job->id);
                $this->deleteArtifactDir($job->artifactDir);
            } else {
                $code = ($error instanceof AuditFailedException) ? $error->errorCode : ($error->getCode() ?: 'audit-failed');
                if (is_int($code)) {
                    $code = 'audit-failed';
                }
                $message = $error->getMessage() ?: 'Audit gagal.';

                if (in_array($code, self::TRANSIENT_CODES, true) && ($current !== null && $current->attempts < 2)) {
                    $this->store->retry($job->id, ['code' => $code, 'message' => $message]);
                } else {
                    $this->store->fail($job->id, ['code' => $code, 'message' => $message]);
                    $this->deleteArtifactDir($job->artifactDir);
                }
            }
        } finally {
            $this->activeJobId = null;
            $this->activeJobCancelled = false;
        }
    }

    private function deleteArtifactDir(string $dir): void
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
                $this->deleteArtifactDir($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($dir);
    }
}
