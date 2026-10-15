<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditJob;

interface AuditorInterface
{
    /**
     * Audit a job, supporting checkpoint recovery, progress updates, and cancellation checks.
     *
     * @param AuditJob $job
     * @param (callable(array{progress: int, stage: string}): void)|null $onProgress
     * @param (callable(): bool)|null $shouldCancel
     * @return array{snapshot: ?array, artifacts: array<string, ?string>}
     */
    public function audit(AuditJob $job, ?callable $onProgress = null, ?callable $shouldCancel = null): array;
}
