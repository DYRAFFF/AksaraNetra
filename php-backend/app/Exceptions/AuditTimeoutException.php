<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

class AuditTimeoutException extends RuntimeException
{
    public function __construct(string $message = 'Audit melewati batas waktu.')
    {
        parent::__construct($message);
    }
}
