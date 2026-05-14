<?php

namespace App\Domain\ClientPortal\Write\Contracts;

use App\Domain\ClientPortal\Write\Models\MutationEvent;

interface MutationEventRecorder
{
    public function record(MutationEvent $event): void;
}