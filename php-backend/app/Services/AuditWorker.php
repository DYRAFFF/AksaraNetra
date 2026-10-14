<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditJob;

class AuditWorker implements AuditWorkerInterface
{
    public function __construct(
        private readonly JobStore $store
    ) {}

    public function kick(): void
    {
        // Long-running Artisan worker process polls the database.
    }

    public function cancel(string $id): ?AuditJob
    {
        return $this->store->requestCancel($id);
    }
}
