<?php

namespace App\Domain\ClientPortal\Write\Models;

use App\Domain\ClientPortal\Write\Support\MutationViolation;

final class TaskStatusMutationExecution
{
    /**
     * @param array<int, MutationViolation> $violations
     * @param array<string, mixed> $details
     */
    private function __construct(
        public readonly string $state,
        public readonly ?TaskStatusMutationResult $result = null,
        public readonly array $violations = [],
        public readonly ?string $reason = null,
        public readonly array $details = [],
        public readonly bool $replayed = false,
    ) {
    }

    public static function success(TaskStatusMutationResult $result, bool $replayed = false): self
    {
        return new self('success', $result, [], null, [], $replayed);
    }

    public static function notFound(): self
    {
        return new self('not_found');
    }

    /**
     * @param array<int, MutationViolation> $violations
     */
    public static function validationFailed(array $violations): self
    {
        return new self('validation_failed', null, array_values($violations), 'VALIDATION_ERROR');
    }

    /**
     * @param array<string, mixed> $details
     */
    public static function conflict(string $reason, array $details = []): self
    {
        return new self('conflict', null, [], $reason, $details);
    }

    public static function internalError(): self
    {
        return new self('internal_error');
    }

    public function successful(): bool
    {
        return $this->state === 'success' && $this->result instanceof TaskStatusMutationResult;
    }
}