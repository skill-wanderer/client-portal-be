<?php

namespace App\Domain\ClientPortal\Enums;

enum WorkspaceStatus: string
{
    case Active = 'active';
    case Provisioning = 'provisioning';
    case Suspended = 'suspended';
}