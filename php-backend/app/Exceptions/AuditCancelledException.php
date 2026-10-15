<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

class AuditCancelledException extends RuntimeException
{
    public function __construct(string $message = 'Audit dibatalkan.')
    {
        parent::__construct($message);
    }
}
