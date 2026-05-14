<?php

namespace App\Domain\ClientPortal\Models;

use App\Domain\ClientPortal\Enums\ProjectDetailResolution;

final class ProjectDetailLookup
{
    public function __construct(
        public readonly ?ProjectDetailProjection $project,
        public readonly ProjectDetailResolution $resolution,
    ) {
    }
}