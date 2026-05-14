<?php

namespace App\Domain\ClientPortal\Write\Validation;

use App\Domain\ClientPortal\Write\Commands\UpdateProjectCommand;
use App\Domain\ClientPortal\Write\Support\MutationViolation;

final class UpdateProjectCommandValidator
{
    /**
     * @return array<int, MutationViolation>
     */
    public function validate(UpdateProjectCommand $command): array
    {
        $violations = [];

        if (trim($command->projectId) === '') {
            $violations[] = new MutationViolation('project_id_required', 'A project id is required.', 'projectId');
        }

        if (
            $command->name === null
            && $command->description === null
            && $command->visibility === null
            && $command->targetStatus === null
        ) {
            $violations[] = new MutationViolation('project_change_required', 'At least one project field must change.');
        }

        if ($command->name !== null && trim($command->name) === '') {
            $violations[] = new MutationViolation('project_name_required', 'A project name may not be blank.', 'name');
        }

        if ($command->name !== null && mb_strlen(trim($command->name)) > 120) {
            $violations[] = new MutationViolation('project_name_too_long', 'The project name may not exceed 120 characters.', 'name');
        }

        if ($command->description !== null && mb_strlen($command->description) > 2000) {
            $violations[] = new MutationViolation('project_description_too_long', 'The project description may not exceed 2000 characters.', 'description');
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