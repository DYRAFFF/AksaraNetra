<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditJob;

interface AuditWorkerInterface
{
    /**
     * Notify the worker that new jobs may be available.
     */
    public function kick(): void;

    /**
     * Request cancellation of a job by ID.
     */
    public function cancel(string $id): ?AuditJob;

    /**
     * Atomically claim and process the next queued job, if any.
     *
     * @return bool True if a job was processed, false if queue was empty.
     */
    public function processNext(): bool;

    /**
     * Process all queued jobs until the queue is empty.
     *
     * @return int Number of jobs processed.
     */
    public function drain(): int;

    /**
     * Recover jobs that were left running due to a previous crash or shutdown.
     *
     * @return int Number of jobs recovered.
     */
    public function recover(): int;

    /**
     * Request the worker to stop processing new jobs.
     */
    public function stop(): void;

    /**
     * Check if the worker has been stopped.
     */
    public function isStopped(): bool;
}
