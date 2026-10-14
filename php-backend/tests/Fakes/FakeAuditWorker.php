<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Models\AuditJob;
use App\Services\AuditWorkerInterface;
use App\Services\JobStore;

class FakeAuditWorker implements AuditWorkerInterface
{
    public int $kicks = 0;

    public function __construct(
        private readonly JobStore $store
    ) {}

    public function kick(): void
    {
        $this->kicks++;
    }

    public function cancel(string $id): ?AuditJob
    {
        return $this->store->requestCancel($id);
    }
}
