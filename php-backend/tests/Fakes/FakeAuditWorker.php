<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Models\AuditJob;
use App\Services\AuditWorkerInterface;
use App\Services\JobStore;

class FakeAuditWorker implements AuditWorkerInterface
{
    public int $kicks = 0;
    public bool $stopped = false;

    public function __construct(
        private readonly ?JobStore $store = null
    ) {}

    public function kick(): void
    {
        $this->kicks++;
    }

    public function cancel(string $id): ?AuditJob
    {
        return $this->store?->requestCancel($id);
    }

    public function processNext(): bool
    {
        return false;
    }

    public function drain(): int
    {
        return 0;
    }

    public function recover(): int
    {
        return $this->store?->recoverInterrupted() ?? 0;
    }

    public function stop(): void
    {
        $this->stopped = true;
    }

    public function isStopped(): bool
    {
        return $this->stopped;
    }
}
