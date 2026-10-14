<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditJob;

interface AuditWorkerInterface
{
    /**
     * Trigger worker processing.
     */
    public function kick(): void;

    /**
     * Request cancellation of a job.
     */
    public function cancel(string $id): ?AuditJob;
}
