<?php

namespace App\Domain\ClientPortal\Write\Validation;

use App\Domain\ClientPortal\Enums\ProjectStatus;
use App\Domain\ClientPortal\Write\Commands\CreateProjectCommand;
use App\Domain\ClientPortal\Write\Support\MutationViolation;

final class CreateProjectCommandValidator
{
    /**
     * @return array<int, MutationViolation>
     */
    public function validate(CreateProjectCommand $command): array
    {
        $violations = [];

        if (trim($command->projectId) === '') {
            $violations[] = new MutationViolation('project_id_required', 'A project id is required.', 'projectId');
        }

        if (trim($command->name) === '') {
            $violations[] = new MutationViolation('project_name_required', 'A project name is required.', 'name');
        }

        if (mb_strlen(trim($command->name)) > 120) {
            $violations[] = new MutationViolation('project_name_too_long', 'The project name may not exceed 120 characters.', 'name');
        }

        if (mb_strlen($command->description) > 2000) {
            $violations[] = new MutationViolation('project_description_too_long', 'The project description may not exceed 2000 characters.', 'description');
        }

        if (! in_array($command->initialStatus, [ProjectStatus::Draft, ProjectStatus::Active], true)) {
            $violations[] = new MutationViolation('invalid_project_initial_status', 'Projects may only be created in draft or active state.', 'initialStatus');
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