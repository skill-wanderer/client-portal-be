<?php

namespace App\Domain\ClientPortal\Write\Models;

enum WriteTransactionBoundary: string
{
    case ProjectCreate = 'project.create';
    case ProjectUpdate = 'project.update';
    case ProjectArchive = 'project.archive';
    case TaskCreate = 'task.create';
    case TaskStatusUpdate = 'task.status.update';
}