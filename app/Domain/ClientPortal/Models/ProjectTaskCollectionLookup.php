<?php

namespace App\Domain\ClientPortal\Models;

use App\Domain\ClientPortal\Enums\ProjectDetailResolution;

final class ProjectTaskCollectionLookup
{
    public function __construct(
        public readonly ?ProjectTaskCollectionProjection $collection,
        public readonly ProjectDetailResolution $resolution,
    ) {
    }
}