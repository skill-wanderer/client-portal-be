<?php

namespace App\Security\Keycloak;

use JsonSerializable;

class KeycloakPrincipal implements JsonSerializable
{
    /**
     * @param array<int, string> $audiences
     * @param array<int, string> $realmRoles
     * @param array<string, mixed> $rawClaims
     */
    public function __construct(
        public readonly string $sub,
        public readonly string $issuer,
        public readonly string $realm,
        public readonly ?string $preferredUsername,
        public readonly ?string $email,
        public readonly ?string $name,
        public readonly array $audiences,
        public readonly array $realmRoles,
        public readonly array $rawClaims,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(bool $includeRawClaims = true): array
    {
        $payload = [
            'sub' => $this->sub,
            'issuer' => $this->issuer,
            'realm' => $this->realm,
            'preferred_username' => $this->preferredUsername,
            'email' => $this->email,
            'name' => $this->name,
            'audiences' => $this->audiences,
            'realm_roles' => $this->realmRoles,
        ];

        if ($includeRawClaims) {
            $payload['raw_claims'] = $this->rawClaims;
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
