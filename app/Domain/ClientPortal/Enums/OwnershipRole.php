<?php

namespace App\Domain\ClientPortal\Enums;

enum OwnershipRole: string
{
    case Owner = 'owner';
    case Collaborator = 'collaborator';
    case Viewer = 'viewer';
}