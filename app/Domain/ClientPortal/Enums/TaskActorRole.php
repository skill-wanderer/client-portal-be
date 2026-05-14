<?php

namespace App\Domain\ClientPortal\Enums;

enum TaskActorRole: string
{
    case Assignee = 'assignee';
    case Reviewer = 'reviewer';
}