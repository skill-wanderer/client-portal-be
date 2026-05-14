<?php

namespace Database\Seeders;

use App\Domain\ClientPortal\Enums\TaskActorRole;
use App\Domain\ClientPortal\Enums\TaskPriority;
use App\Domain\ClientPortal\Enums\TaskStatus;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ClientPortalReadModelSeeder extends Seeder
{
    public function run(): void
    {
        if (Schema::hasTable('client_mutation_events')) {
            DB::table('client_mutation_events')->delete();
        }

        if (Schema::hasTable('client_mutation_idempotency')) {
            DB::table('client_mutation_idempotency')->delete();
        }

        DB::table('client_tasks')->delete();
        DB::table('client_projects')->delete();
        DB::table('client_workspaces')->delete();

        $workspaces = [
            $this->workspaceRow('user-123', 'test@reltroner.com'),
            $this->workspaceRow('browser-user-123', 'browser@reltroner.com'),
            [
                'id' => 'workspace-external',
                'owner_sub' => 'external-user-001',
                'owner_email' => 'external@reltroner.com',
                'name' => 'External Workspace',
                'status' => 'active',
                'ownership_role' => 'owner',
                'created_at' => '2026-01-01 00:00:00',
                'updated_at' => '2026-05-10 08:00:00',
            ],
        ];

        DB::table('client_workspaces')->insert($workspaces);

        $projects = [];
        $tasks = [];

        foreach ([
            ['ownerSub' => 'user-123', 'email' => 'test@reltroner.com'],
            ['ownerSub' => 'browser-user-123', 'email' => 'browser@reltroner.com'],
        ] as $owner) {
            $workspaceId = $this->workspaceId($owner['ownerSub']);
            $workspaceSuffix = $this->workspaceSuffix($workspaceId);

            $atlasId = 'project-'.$workspaceSuffix.'-atlas';
            $onboardingId = 'project-'.$workspaceSuffix.'-onboarding';
            $knowledgeId = 'project-'.$workspaceSuffix.'-knowledge';

            $projects[] = $this->projectRow(
                id: $atlasId,
                workspaceId: $workspaceId,
                name: 'Atlas Migration',
                description: 'Coordinates the staged migration of legacy client workflows into the new portal.',
                status: 'active',
                visibility: 'shared',
                archived: false,
                taskCount: 18,
                activeTaskCount: 18,
                completedTaskCount: 11,
                fileCount: 7,
                pendingActionCount: 3,
                createdAt: '2026-02-01 09:00:00',
                updatedAt: '2026-05-08 15:30:00',
            );
            $projects[] = $this->projectRow(
                id: $onboardingId,
                workspaceId: $workspaceId,
                name: 'Client Onboarding Refresh',
                description: 'Tracks onboarding improvements for newly provisioned client workspaces.',
                status: 'on_hold',
                visibility: 'private',
                archived: false,
                taskCount: 9,
                activeTaskCount: 9,
                completedTaskCount: 4,
                fileCount: 3,
                pendingActionCount: 2,
                createdAt: '2026-01-15 10:00:00',
                updatedAt: '2026-04-28 09:00:00',
            );
            $projects[] = $this->projectRow(
                id: $knowledgeId,
                workspaceId: $workspaceId,
                name: 'Knowledge Base Rollout',
                description: 'Publishes the client-facing knowledge base and closes legacy help center gaps.',
                status: 'completed',
                visibility: 'shared',
                archived: true,
                taskCount: 14,
                activeTaskCount: 0,
                completedTaskCount: 0,
                fileCount: 12,
                pendingActionCount: 0,
                createdAt: '2025-12-10 13:15:00',
                updatedAt: '2026-04-20 17:45:00',
            );

            $tasks = array_merge(
                $tasks,
                $this->atlasTasks($atlasId, $workspaceId, $owner['ownerSub'], $owner['email']),
                $this->genericTasks($onboardingId, $workspaceId, 'Client Onboarding Refresh', $owner['ownerSub'], $owner['email'], 9, 4, false),
                $this->genericTasks($knowledgeId, $workspaceId, 'Knowledge Base Rollout', $owner['ownerSub'], $owner['email'], 14, 14, true),
            );
        }

        $projects[] = $this->projectRow(
            id: 'project-external-ops',
            workspaceId: 'workspace-external',
            name: 'External Ops Sandbox',
            description: 'A different workspace aggregate used to verify ownership boundaries.',
            status: 'active',
            visibility: 'private',
            archived: false,
            taskCount: 6,
            activeTaskCount: 6,
            completedTaskCount: 1,
            fileCount: 2,
            pendingActionCount: 4,
            createdAt: '2026-03-01 12:00:00',
            updatedAt: '2026-05-10 08:00:00',
        );

        $tasks = array_merge(
            $tasks,
            $this->genericTasks('project-external-ops', 'workspace-external', 'External Ops Sandbox', 'external-user-001', 'external@reltroner.com', 6, 1, false),
        );

        DB::table('client_projects')->insert($projects);
        DB::table('client_tasks')->insert($tasks);
    }

    /**
     * @return array<string, string>
     */
    private function workspaceRow(string $ownerSub, string $email): array
    {
        return [
            'id' => $this->workspaceId($ownerSub),
            'owner_sub' => $ownerSub,
            'owner_email' => $email,
            'name' => 'Client Workspace',
            'status' => 'active',
            'ownership_role' => 'owner',
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-05-10 08:00:00',
        ];
    }

    /**
     * @return array<string, int|string|bool>
     */
    private function projectRow(
        string $id,
        string $workspaceId,
        string $name,
        string $description,
        string $status,
        string $visibility,
        bool $archived,
        int $taskCount,
        int $activeTaskCount,
        int $completedTaskCount,
        int $fileCount,
        int $pendingActionCount,
        string $createdAt,
        string $updatedAt,
    ): array {
        return [
            'id' => $id,
            'workspace_id' => $workspaceId,
            'name' => $name,
            'description' => $description,
            'status' => $status,
            'visibility' => $visibility,
            'archived' => $archived,
            'task_count' => $taskCount,
            'active_task_count' => $activeTaskCount,
            'completed_task_count' => $completedTaskCount,
            'file_count' => $fileCount,
            'pending_action_count' => $pendingActionCount,
            'version' => 1,
            'created_at' => $createdAt,
            'updated_at' => $updatedAt,
        ];
    }

    /**
     * @return array<int, array<string, int|string|bool|null>>
     */
    private function atlasTasks(string $projectId, string $workspaceId, string $actorId, string $actorEmail): array
    {
        $tasks = [
            $this->taskRow($projectId.'-task-scope', $projectId, $workspaceId, 'Audit migration scope', $actorId, $actorEmail, TaskActorRole::Assignee, TaskStatus::InProgress, TaskPriority::Urgent, '2026-05-12 12:00:00', null, false, '2026-04-28 09:00:00', '2026-05-10 16:00:00'),
            $this->taskRow($projectId.'-task-permissions', $projectId, $workspaceId, 'Map legacy permissions', $actorId, $actorEmail, TaskActorRole::Reviewer, TaskStatus::Blocked, TaskPriority::High, '2026-05-14 12:00:00', null, false, '2026-04-25 09:00:00', '2026-05-09 15:00:00'),
            $this->taskRow($projectId.'-task-pilot', $projectId, $workspaceId, 'Prepare pilot rollout', $actorId, $actorEmail, TaskActorRole::Assignee, TaskStatus::Todo, TaskPriority::Medium, '2026-05-18 12:00:00', null, false, '2026-04-30 09:00:00', '2026-05-08 14:00:00'),
            $this->taskRow($projectId.'-task-sso', $projectId, $workspaceId, 'Confirm SSO dependencies', $actorId, $actorEmail, TaskActorRole::Reviewer, TaskStatus::Done, TaskPriority::High, '2026-05-06 12:00:00', '2026-05-06 16:00:00', false, '2026-04-20 09:00:00', '2026-05-06 16:00:00'),
            $this->taskRow($projectId.'-task-links', $projectId, $workspaceId, 'Archive legacy support links', $actorId, $actorEmail, TaskActorRole::Assignee, TaskStatus::Todo, TaskPriority::Low, null, null, false, '2026-05-01 09:00:00', '2026-05-07 13:00:00'),
        ];

        for ($index = 6; $index <= 18; $index++) {
            $status = $index <= 15 ? TaskStatus::Done : match ($index) {
                16 => TaskStatus::InProgress,
                17 => TaskStatus::Blocked,
                default => TaskStatus::Todo,
            };
            $priority = match ($index % 4) {
                0 => TaskPriority::Low,
                1 => TaskPriority::Medium,
                2 => TaskPriority::High,
                default => TaskPriority::Urgent,
            };
            $updatedAt = sprintf('2026-05-%02d 11:00:00', min(5, 1 + ($index % 5)));
            $dueAt = $index % 5 === 0 ? null : sprintf('2026-06-%02d 12:00:00', min(28, $index + 2));

            $tasks[] = $this->taskRow(
                id: $projectId.'-task-'.str_pad((string) $index, 2, '0', STR_PAD_LEFT),
                projectId: $projectId,
                workspaceId: $workspaceId,
                title: 'Atlas Migration Task '.str_pad((string) $index, 2, '0', STR_PAD_LEFT),
                actorId: $actorId,
                actorEmail: $actorEmail,
                actorRole: $index % 2 === 0 ? TaskActorRole::Assignee : TaskActorRole::Reviewer,
                status: $status,
                priority: $priority,
                dueAt: $dueAt,
                completedAt: $status === TaskStatus::Done ? $updatedAt : null,
                archived: false,
                createdAt: sprintf('2026-04-%02d 09:00:00', min(28, $index)),
                updatedAt: $updatedAt,
            );
        }

        return $tasks;
    }

    /**
     * @return array<int, array<string, int|string|bool|null>>
     */
    private function genericTasks(
        string $projectId,
        string $workspaceId,
        string $projectName,
        string $actorId,
        string $actorEmail,
        int $total,
        int $completedCount,
        bool $archived,
    ): array {
        $tasks = [];

        for ($index = 1; $index <= $total; $index++) {
            $status = $index <= $completedCount
                ? TaskStatus::Done
                : match (($index - $completedCount) % 3) {
                    1 => TaskStatus::Todo,
                    2 => TaskStatus::InProgress,
                    default => TaskStatus::Blocked,
                };
            $priority = match ($index % 4) {
                0 => TaskPriority::Low,
                1 => TaskPriority::Medium,
                2 => TaskPriority::High,
                default => TaskPriority::Urgent,
            };
            $createdAt = sprintf('2026-03-%02d 09:00:00', min(28, $index));
            $updatedAt = sprintf('2026-04-%02d 10:00:00', min(28, $index + 1));
            $dueAt = $archived
                ? sprintf('2026-03-%02d 12:00:00', min(28, $index + 3))
                : sprintf('2026-05-%02d 12:00:00', min(28, $index + 8));

            $tasks[] = $this->taskRow(
                id: $projectId.'-task-'.str_pad((string) $index, 2, '0', STR_PAD_LEFT),
                projectId: $projectId,
                workspaceId: $workspaceId,
                title: $projectName.' Task '.str_pad((string) $index, 2, '0', STR_PAD_LEFT),
                actorId: $actorId,
                actorEmail: $actorEmail,
                actorRole: TaskActorRole::Assignee,
                status: $status,
                priority: $priority,
                dueAt: $dueAt,
                completedAt: $status === TaskStatus::Done ? $updatedAt : null,
                archived: $archived,
                createdAt: $createdAt,
                updatedAt: $updatedAt,
            );
        }

        return $tasks;
    }

    /**
     * @return array<string, int|string|bool|null>
     */
    private function taskRow(
        string $id,
        string $projectId,
        string $workspaceId,
        string $title,
        string $actorId,
        string $actorEmail,
        TaskActorRole $actorRole,
        TaskStatus $status,
        TaskPriority $priority,
        ?string $dueAt,
        ?string $completedAt,
        bool $archived,
        string $createdAt,
        string $updatedAt,
    ): array {
        return [
            'id' => $id,
            'project_id' => $projectId,
            'workspace_id' => $workspaceId,
            'title' => $title,
            'description' => 'Task details for '.$title.'.',
            'actor_id' => $actorId,
            'actor_email' => $actorEmail,
            'actor_role' => $actorRole->value,
            'status' => $status->value,
            'priority' => $priority->value,
            'due_at' => $dueAt,
            'completed_at' => $completedAt,
            'archived' => $archived,
            'version' => 1,
            'created_at' => $createdAt,
            'updated_at' => $updatedAt,
        ];
    }

    private function workspaceId(string $ownerSub): string
    {
        return 'workspace-'.substr(sha1($ownerSub), 0, 10);
    }

    private function workspaceSuffix(string $workspaceId): string
    {
        return substr(sha1($workspaceId), 0, 8);
    }
}