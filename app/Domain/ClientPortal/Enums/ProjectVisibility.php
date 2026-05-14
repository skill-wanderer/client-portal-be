<?php

namespace App\Domain\ClientPortal\Enums;

enum ProjectVisibility: string
{
    case Private = 'private';
    case Shared = 'shared';
}