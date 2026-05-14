<?php

namespace App\Domain\ClientPortal\Write\Models;

enum ProjectCounterName: string
{
    case TaskCount = 'task_count';
    case ActiveTaskCount = 'active_task_count';
    case CompletedTaskCount = 'completed_task_count';
    case PendingActionCount = 'pending_action_count';
    case FileCount = 'file_count';
}