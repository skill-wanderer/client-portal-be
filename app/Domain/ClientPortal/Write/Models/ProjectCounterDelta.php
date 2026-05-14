<?php

namespace App\Domain\ClientPortal\Write\Models;

final class ProjectCounterDelta
{
    public function __construct(
        public readonly ProjectCounterName $counter,
        public readonly int $delta,
        public readonly string $reason,
    ) {
    }
}