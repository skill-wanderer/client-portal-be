<?php

namespace App\Domain\ClientPortal\Write\Validation;

use App\Domain\ClientPortal\Write\Commands\CreateTaskCommand;
use App\Domain\ClientPortal\Write\Support\MutationViolation;

final class CreateTaskCommandValidator
{
    /**
     * @return array<int, MutationViolation>
     */
    public function validate(CreateTaskCommand $command): array
    {
        $violations = [];

        if (trim($command->projectId) === '') {
            $violations[] = new MutationViolation('project_id_required', 'A project id is required.', 'projectId');
        }

        if (trim($command->title) === '') {
            $violations[] = new MutationViolation('task_title_required', 'A task title is required.', 'title');
        }

        if (mb_strlen(trim($command->title)) > 160) {
            $violations[] = new MutationViolation('task_title_too_long', 'The task title may not exceed 160 characters.', 'title');
        }

        if (trim($command->description) === '') {
            $violations[] = new MutationViolation('task_description_required', 'A task description is required.', 'description');
        }

        if (mb_strlen(trim($command->description)) > 2000) {
            $violations[] = new MutationViolation('task_description_too_long', 'The task description may not exceed 2000 characters.', 'description');
        }

        if (trim($command->assigneeId) === '') {
            $violations[] = new MutationViolation('task_assignee_required', 'An assignee id is required.', 'assigneeId');
        }

        if (trim($command->assigneeEmail) === '') {
            $violations[] = new MutationViolation('task_assignee_email_required', 'An assignee email is required.', 'assigneeEmail');
        }

        if (trim($command->metadata->correlationId) === '') {
            $violations[] = new MutationViolation('correlation_id_required', 'A correlation id is required.', 'correlationId');
        }

        if (trim((string) $command->metadata->idempotencyKey) === '') {
            $violations[] = new MutationViolation('idempotency_key_required', 'An idempotency key is required for create operations.', 'idempotencyKey');
        }

        return $violations;
    }
}