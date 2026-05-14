<?php

namespace App\Domain\ClientPortal\Enums;

enum FileVisibility: string
{
    case Private = 'private';
    case Shared = 'shared';
}