<?php

namespace App\Infrastructure\ClientPortal\Write;

use App\Domain\ClientPortal\Write\Contracts\WriteTransactionManager;
use App\Domain\ClientPortal\Write\Models\WriteTransactionBoundary;
use Illuminate\Support\Facades\DB;

final class DatabaseWriteTransactionManager implements WriteTransactionManager
{
    public function within(WriteTransactionBoundary $boundary, callable $callback): mixed
    {
        return DB::transaction(static fn () => $callback($boundary));
    }
}