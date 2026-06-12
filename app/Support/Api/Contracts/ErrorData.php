<?php

namespace App\Support\Api\Contracts;

final class ErrorData implements ApiDataContract
{
    /**
     * @param array<string, mixed> $details
     */
    public function __construct(
        public readonly string $code,
        public readonly string $message,
        public readonly ?string $reason = null,
        public readonly ?bool $authenticated = null,
        public readonly array $details = [],
        public readonly ?string $failureCode = null,
        public readonly ?string $recoveryHint = null,
        public readonly ?bool $retryable = null,
        public readonly ?string $runtimeBoundary = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $payload = [
            'code' => $this->code,
            'message' => $this->message,
        ];

        if ($this->reason !== null) {
            $payload['reason'] = $this->reason;
        }

        if ($this->authenticated !== null) {
            $payload['authenticated'] = $this->authenticated;
        }

        if ($this->details !== []) {
            $payload['details'] = $this->details;
        }

        if ($this->failureCode !== null) {
            $payload['failure_code'] = $this->failureCode;
        }

        if ($this->recoveryHint !== null) {
            $payload['recovery_hint'] = $this->recoveryHint;
        }

        if ($this->retryable !== null) {
            $payload['retryable'] = $this->retryable;
        }

        if ($this->runtimeBoundary !== null) {
            $payload['runtime_boundary'] = $this->runtimeBoundary;
        }

        return $payload;
    }
}