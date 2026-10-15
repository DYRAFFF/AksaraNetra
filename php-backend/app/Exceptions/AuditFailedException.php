<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;
use Throwable;

class AuditFailedException extends RuntimeException
{
    public readonly string $errorCode;

    public function __construct(
        string $errorCode = 'audit-failed',
        public readonly int|string|null $stage = null,
        string $message = 'Audit gagal.',
        ?Throwable $previous = null
    ) {
        $this->errorCode = $errorCode;
        parent::__construct($message, 0, $previous);
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }
}
