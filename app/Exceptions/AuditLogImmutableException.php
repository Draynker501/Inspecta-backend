<?php

namespace App\Exceptions;

use RuntimeException;

class AuditLogImmutableException extends RuntimeException
{
    public function __construct(string $message = 'AuditLog es append-only: no se permite update ni delete.')
    {
        parent::__construct($message);
    }
}
