<?php

namespace App\Services\Auth\Exceptions;

use RuntimeException;
use Throwable;

class InvalidBearerTokenException extends RuntimeException
{
    public function __construct(
        private readonly string $reason = 'INVALID_BEARER_TOKEN',
        string $message = 'Invalid bearer token.',
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function reason(): string
    {
        return $this->reason;
    }
}
