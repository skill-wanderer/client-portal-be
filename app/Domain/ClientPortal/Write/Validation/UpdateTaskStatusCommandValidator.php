<?php

namespace App\Domain\ClientPortal\Write\Validation;

use App\Domain\ClientPortal\Write\Commands\UpdateTaskStatusCommand;
use App\Domain\ClientPortal\Write\Support\MutationViolation;

final class UpdateTaskStatusCommandValidator
{
    /**
     * @return array<int, MutationViolation>
     */
    public function validate(UpdateTaskStatusCommand $command): array
    {
        $violations = [];

        if (trim($command->projectId) === '') {
            $violations[] = new MutationViolation('project_id_required', 'A project id is required.', 'projectId');
        }

        if (trim($command->taskId) === '') {
            $violations[] = new MutationViolation('task_id_required', 'A task id is required.', 'taskId');
        }

        if (trim($command->metadata->correlationId) === '') {
            $violations[] = new MutationViolation('correlation_id_required', 'A correlation id is required.', 'correlationId');
        }

        if ($command->metadata->expectedVersion === null || $command->metadata->expectedVersion < 1) {
            $violations[] = new MutationViolation('expected_version_required', 'A positive expected version is required for updates.', 'expectedVersion');
        }

        return $violations;
    }
}