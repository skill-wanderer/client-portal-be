<?php

namespace App\Support\Security;

use JsonException;

final class AuthStateCookieData
{
    public function __construct(
        public readonly string $state,
        public readonly ?string $correlationId = null,
        public readonly ?string $authFlowId = null,
    ) {
    }

    public function encode(): string
    {
        $payload = [
            'v' => 1,
            'state' => $this->state,
        ];

        if ($this->correlationId !== null && $this->correlationId !== '') {
            $payload['correlation_id'] = $this->correlationId;
        }

        if ($this->authFlowId !== null && $this->authFlowId !== '') {
            $payload['auth_flow_id'] = $this->authFlowId;
        }

        try {
            $json = json_encode($payload, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $this->state;
        }

        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }

    public static function decode(?string $value): ?self
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $normalizedValue = trim($value);
        $payload = self::decodePayload($normalizedValue);

        if ($payload === null) {
            $urlDecodedValue = rawurldecode($normalizedValue);

            if ($urlDecodedValue !== $normalizedValue) {
                $payload = self::decodePayload($urlDecodedValue);
                $normalizedValue = $urlDecodedValue;
            }
        }

        if ($payload === null) {
            return new self($normalizedValue);
        }

        $state = $payload['state'] ?? null;

        if (! is_string($state) || trim($state) === '') {
            return null;
        }

        $correlationId = $payload['correlation_id'] ?? null;
        $authFlowId = $payload['auth_flow_id'] ?? null;

        return new self(
            state: trim($state),
            correlationId: is_string($correlationId) && trim($correlationId) !== '' ? trim($correlationId) : null,
            authFlowId: is_string($authFlowId) && trim($authFlowId) !== '' ? trim($authFlowId) : null,
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function decodePayload(string $value): ?array
    {
        $normalizedPayload = strtr($value, '-_', '+/');
        $remainder = strlen($normalizedPayload) % 4;

        if ($remainder > 0) {
            $normalizedPayload .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode($normalizedPayload, true);

        if ($decoded === false) {
            return null;
        }

        try {
            $payload = json_decode($decoded, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($payload) ? $payload : null;
    }
}