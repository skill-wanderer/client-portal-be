<?php

namespace App\Security\Keycloak\Exceptions;

use RuntimeException;

class InsufficientKeycloakRoleException extends RuntimeException
{
    public function __construct(
        private readonly string $reason = 'INSUFFICIENT_KEYCLOAK_ROLE',
        string $message = 'Insufficient Keycloak realm role.',
    ) {
        parent::__construct($message);
    }

    public function reason(): string
    {
        return $this->reason;
    }
}
