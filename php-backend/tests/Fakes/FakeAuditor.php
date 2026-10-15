<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Exceptions\AuditCancelledException;
use App\Exceptions\AuditFailedException;
use App\Models\AuditJob;
use App\Services\AuditorInterface;

class FakeAuditor implements AuditorInterface
{
    /** @var (callable(AuditJob, ?callable, ?callable): array)|null */
    private $handler = null;

    /** @var array<string, mixed>|null */
    private ?array $failure = null;

    private int $callCount = 0;

    public function setHandler(?callable $handler): void
    {
        $this->handler = $handler;
    }

    public function setFailure(string $code = 'browser-failed', string $message = 'Browser failed', int $onlyOnAttempt = 0): void
    {
        $this->failure = [
            'code' => $code,
            'message' => $message,
            'onlyOnAttempt' => $onlyOnAttempt,
        ];
    }

    public function getCallCount(): int
    {
        return $this->callCount;
    }

    public function reset(): void
    {
        $this->callCount = 0;
        $this->handler = null;
        $this->failure = null;
    }

    public function audit(AuditJob $job, ?callable $onProgress = null, ?callable $shouldCancel = null): array
    {
        $this->callCount++;

        if ($shouldCancel !== null && $shouldCancel()) {
            throw new AuditCancelledException('Audit dibatalkan.');
        }

        if ($this->handler !== null) {
            return ($this->handler)($job, $onProgress, $shouldCancel);
        }

        if ($this->failure !== null) {
            $targetAttempt = $this->failure['onlyOnAttempt'];
            if ($targetAttempt === 0 || $targetAttempt === $job->attempts) {
                throw new AuditFailedException($this->failure['code'], null, $this->failure['message']);
            }
        }

        if ($onProgress !== null) {
            $onProgress(['progress' => 50, 'stage' => 'Sedang audit']);
        }

        if (!is_dir($job->artifactDir)) {
            mkdir($job->artifactDir, 0777, true);
        }

        file_put_contents($job->artifactDir . DIRECTORY_SEPARATOR . 'snapshot.json', json_encode(['ok' => true]));
        file_put_contents($job->artifactDir . DIRECTORY_SEPARATOR . 'engine-result.json', json_encode(['ok' => true]));
        file_put_contents($job->artifactDir . DIRECTORY_SEPARATOR . 'reader.html', '<h1>Reader</h1>');
        file_put_contents($job->artifactDir . DIRECTORY_SEPARATOR . 'patched.html', '<h1>Patched</h1>');
        file_put_contents($job->artifactDir . DIRECTORY_SEPARATOR . 'report.html', '<h1>Report</h1>');
        file_put_contents($job->artifactDir . DIRECTORY_SEPARATOR . 'report.pdf', '%PDF fake');
        file_put_contents($job->artifactDir . DIRECTORY_SEPARATOR . 'after.png', 'PNG fake');

        return [
            'snapshot' => ['ok' => true],
            'artifacts' => [
                'reader' => 'reader.html',
                'patched' => 'patched.html',
                'reportPdf' => 'report.pdf',
                'screenshot' => 'after.png',
            ],
        ];
    }
}
