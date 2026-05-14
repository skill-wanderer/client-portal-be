<?php

namespace App\Domain\ClientPortal\Enums;

enum ProjectDetailResolution: string
{
    case Owned = 'owned';
    case NotFound = 'not_found';
    case OutsideWorkspace = 'outside_workspace';
}