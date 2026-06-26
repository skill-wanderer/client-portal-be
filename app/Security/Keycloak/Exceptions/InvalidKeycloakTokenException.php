<?php

namespace App\Security\Keycloak\Exceptions;

use RuntimeException;

class InvalidKeycloakTokenException extends RuntimeException
{
    public function __construct(
        private readonly string $reason = 'INVALID_KEYCLOAK_TOKEN',
        string $message = 'Invalid Keycloak bearer token.',
    ) {
        parent::__construct($message);
    }

    public function reason(): string
    {
        return $this->reason;
    }
}
