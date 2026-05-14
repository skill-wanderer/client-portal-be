<?php

namespace App\Domain\ClientPortal\Write\Support;

final class MutationViolation
{
    public function __construct(
        public readonly string $code,
        public readonly string $message,
        public readonly ?string $field = null,
    ) {
    }
}