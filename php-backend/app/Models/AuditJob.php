<?php

declare(strict_types=1);

namespace App\Models;

use JsonSerializable;

class AuditJob implements JsonSerializable
{
    public function __construct(
        public readonly string $id,
        public readonly string $tokenHash,
        public readonly string $shareTokenHash,
        public readonly string $ipHash,
        public readonly string $targetUrl,
        public readonly string $status,
        public readonly string $stage,
        public readonly int $progress,
        public readonly int $attempts,
        public readonly bool $cancelRequested,
        public readonly ?array $result,
        public readonly ?string $errorCode,
        public readonly ?string $errorMessage,
        public readonly int $createdAt,
        public readonly int $updatedAt,
        public readonly ?int $startedAt,
        public readonly ?int $finishedAt,
        public readonly int $expiresAt,
        public readonly string $artifactDir,
    ) {}

    public static function fromRow(object|array|null $row): ?self
    {
        if (!$row) {
            return null;
        }

        if (is_object($row)) {
            $row = (array) $row;
        }

        return new self(
            id: (string) $row['id'],
            tokenHash: (string) $row['token_hash'],
            shareTokenHash: (string) $row['share_token_hash'],
            ipHash: (string) $row['ip_hash'],
            targetUrl: (string) $row['target_url'],
            status: (string) $row['status'],
            stage: (string) $row['stage'],
            progress: (int) $row['progress'],
            attempts: (int) $row['attempts'],
            cancelRequested: (int) $row['cancel_requested'] === 1,
            result: !empty($row['result_json']) ? json_decode((string) $row['result_json'], true) : null,
            errorCode: $row['error_code'] ?? null,
            errorMessage: $row['error_message'] ?? null,
            createdAt: (int) $row['created_at'],
            updatedAt: (int) $row['updated_at'],
            startedAt: isset($row['started_at']) && $row['started_at'] !== null ? (int) $row['started_at'] : null,
            finishedAt: isset($row['finished_at']) && $row['finished_at'] !== null ? (int) $row['finished_at'] : null,
            expiresAt: (int) $row['expires_at'],
            artifactDir: (string) $row['artifact_dir'],
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'tokenHash' => $this->tokenHash,
            'shareTokenHash' => $this->shareTokenHash,
            'ipHash' => $this->ipHash,
            'targetUrl' => $this->targetUrl,
            'status' => $this->status,
            'stage' => $this->stage,
            'progress' => $this->progress,
            'attempts' => $this->attempts,
            'cancelRequested' => $this->cancelRequested,
            'result' => $this->result,
            'errorCode' => $this->errorCode,
            'errorMessage' => $this->errorMessage,
            'createdAt' => $this->createdAt,
            'updatedAt' => $this->updatedAt,
            'startedAt' => $this->startedAt,
            'finishedAt' => $this->finishedAt,
            'expiresAt' => $this->expiresAt,
            'artifactDir' => $this->artifactDir,
        ];
    }

    public function toPublic(): array
    {
        return [
            'id' => $this->id,
            'targetUrl' => $this->targetUrl,
            'status' => $this->status,
            'stage' => $this->stage,
            'progress' => $this->progress,
            'attempts' => $this->attempts,
            'errorCode' => $this->errorCode,
            'errorMessage' => $this->errorMessage,
            'createdAt' => $this->createdAt,
            'updatedAt' => $this->updatedAt,
            'startedAt' => $this->startedAt,
            'finishedAt' => $this->finishedAt,
            'expiresAt' => $this->expiresAt,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
