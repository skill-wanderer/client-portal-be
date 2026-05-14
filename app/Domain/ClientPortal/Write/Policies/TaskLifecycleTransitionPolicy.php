<?php

namespace App\Domain\ClientPortal\Write\Policies;

use App\Domain\ClientPortal\Enums\TaskStatus;

final class TaskLifecycleTransitionPolicy
{
    public function canTransition(TaskStatus $from, TaskStatus $to): bool
    {
        if ($from === $to) {
            return false;
        }

        return match ($from) {
            TaskStatus::Todo => in_array($to, [TaskStatus::InProgress, TaskStatus::Blocked, TaskStatus::Done], true),
            TaskStatus::InProgress => in_array($to, [TaskStatus::Blocked, TaskStatus::Done], true),
            TaskStatus::Blocked => in_array($to, [TaskStatus::Todo, TaskStatus::InProgress, TaskStatus::Done], true),
            TaskStatus::Done => $to === TaskStatus::Todo,
        };
    }
}