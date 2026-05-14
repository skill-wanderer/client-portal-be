<?php

namespace App\Domain\ClientPortal\Write\Support;

use RuntimeException;

final class StaleWriteException extends RuntimeException
{
    public function __construct(
        public readonly string $aggregateId,
        public readonly int $expectedVersion,
        public readonly ?int $currentVersion = null,
    ) {
        parent::__construct('The aggregate version is stale.');
    }
}