<?php

namespace App\Support\Api\Contracts\ClientPortal;

use App\Domain\ClientPortal\Models\DashboardProjection;
use App\Domain\ClientPortal\Models\FileSummary;
use App\Domain\ClientPortal\Models\ProjectSummary;
use App\Domain\ClientPortal\Models\TaskSummary;
use App\Domain\ClientPortal\Models\WorkspaceContext;
use App\Support\Api\Contracts\ApiDataContract;

final class DashboardData implements ApiDataContract
{
    public function __construct(
        private readonly DashboardProjection $projection,
    ) {
    }

    public static function fromDomain(DashboardProjection $projection): self
    {
        return new self($projection);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $payload = [
            'user' => [
                'id' => $this->projection->user->id,
                'email' => $this->projection->user->email,
            ],
            'dashboard' => [
                'status' => $this->projection->status->value,
            ],
            'summary' => [
                'activeProjects' => $this->projection->summary->activeProjects,
                'pendingActions' => $this->projection->summary->pendingActions,
                'unreadMessages' => $this->projection->summary->unreadMessages,
                'recentFiles' => $this->projection->summary->recentFiles,
            ],
            'projects' => array_map(
                static fn (ProjectSummary $project): array => [
                    'id' => $project->id,
                    'name' => $project->name,
                    'status' => $project->status->value,
                ],
                $this->projection->projects,
            ),
            'tasks' => array_map(
                static fn (TaskSummary $task): array => array_filter([
                    'id' => $task->id,
                    'title' => $task->title,
                    'status' => $task->lifecycle->status->value,
                    'priority' => $task->lifecycle->priority->value,
                    'dueAt' => $task->lifecycle->dueAt?->format(DATE_ATOM),
                ], static fn (mixed $value): bool => $value !== null),
                $this->projection->tasks,
            ),
            'files' => array_map(
                static fn (FileSummary $file): array => array_filter([
                    'id' => $file->id,
                    'name' => $file->name,
                    'visibility' => $file->visibility->value,
                    'sizeBytes' => $file->sizeBytes,
                    'uploadedAt' => $file->uploadedAt?->format(DATE_ATOM),
                ], static fn (mixed $value): bool => $value !== null),
                $this->projection->files,
            ),
        ];

        if ($this->projection->workspace instanceof WorkspaceContext) {
            $workspace = [
                'id' => $this->projection->workspace->id,
                'name' => $this->projection->workspace->name,
                'status' => $this->projection->workspace->status->value,
            ];

            if ($this->projection->workspace->ownershipRole !== null) {
                $workspace['ownershipRole'] = $this->projection->workspace->ownershipRole->value;
            }

            $payload['workspace'] = $workspace;
        }

        return $payload;
    }
}