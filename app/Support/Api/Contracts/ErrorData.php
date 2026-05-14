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

        return $payload;
    }
}