<?php

namespace App\Services\Auth;

use Carbon\CarbonImmutable;

class ValidatedBearerToken
{
    /**
     * @param array<int, string> $audience
     */
    public function __construct(
        public readonly string $subject,
        public readonly string $email,
        public readonly string $role,
        public readonly CarbonImmutable $expiresAt,
        public readonly string $issuer,
        public readonly array $audience,
        public readonly ?string $authorizedParty = null,
    ) {
    }
}
