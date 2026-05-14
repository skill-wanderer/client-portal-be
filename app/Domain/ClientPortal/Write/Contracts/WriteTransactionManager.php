<?php

namespace App\Domain\ClientPortal\Write\Contracts;

use App\Domain\ClientPortal\Write\Models\WriteTransactionBoundary;

interface WriteTransactionManager
{
    public function within(WriteTransactionBoundary $boundary, callable $callback): mixed;
}