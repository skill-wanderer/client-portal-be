<?php

namespace App\Domain\ClientPortal\Write\Support;

use App\Domain\ClientPortal\Write\Models\MutationPlan;

final class MutationDecision
{
    /**
     * @param array<int, MutationViolation> $violations
     */
    public function __construct(
        public readonly ?MutationPlan $plan,
        public readonly array $violations = [],
    ) {
    }

    public static function accept(MutationPlan $plan): self
    {
        return new self($plan, []);
    }

    /**
     * @param array<int, MutationViolation> $violations
     */
    public static function reject(array $violations): self
    {
        return new self(null, array_values($violations));
    }

    public function accepted(): bool
    {
        return $this->plan instanceof MutationPlan && $this->violations === [];
    }
}