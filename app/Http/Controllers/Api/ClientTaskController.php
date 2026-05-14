<?php

namespace App\Http\Controllers\Api;

use App\Domain\ClientPortal\Enums\TaskPriority;
use App\Domain\ClientPortal\Enums\TaskStatus;
use App\Domain\ClientPortal\Write\Commands\CreateTaskCommand;
use App\Domain\ClientPortal\Write\Commands\UpdateTaskStatusCommand;
use App\Domain\ClientPortal\Write\Models\WriteCommandMetadata;
use App\Http\Controllers\Controller;
use App\Http\Middleware\SessionMiddleware;
use App\Http\Middleware\TasksCollectionAuditMiddleware;
use App\Http\Requests\ClientPortal\CreateTaskRequest;
use App\Http\Requests\ClientPortal\UpdateTaskStatusRequest;
use App\Services\ClientPortal\ClientTaskService;
use App\Services\ClientPortal\Write\TaskCreationHandler;
use App\Services\ClientPortal\Write\TaskMutationHandler;
use App\Services\Session\SessionData;
use App\Support\Api\ApiResponse;
use App\Support\Api\Contracts\ClientPortal\TaskCollectionData;
use App\Support\Api\Contracts\ClientPortal\TaskCreationData;
use App\Support\Api\Contracts\ClientPortal\TaskStatusMutationData;
use App\Support\Api\Contracts\ErrorData;
use App\Support\Api\Contracts\PaginationMeta;
use App\Support\Api\Query\ListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Psr\Log\LoggerInterface;
use Illuminate\Support\Str;

class ClientTaskController extends Controller
{
    public function __construct(
        private readonly ClientTaskService $taskService,
        private readonly TaskCreationHandler $taskCreationHandler,
        private readonly TaskMutationHandler $taskMutationHandler,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function index(Request $request, string $projectId): JsonResponse
    {
        $correlationId = $this->resolveCorrelationId($request);
        $session = $request->attributes->get(SessionMiddleware::REQUEST_ATTRIBUTE);

        if (! $session instanceof SessionData) {
            $this->logger->error('tasks.collection.internal_error', [
                'correlation_id' => $correlationId,
                'reason' => 'MISSING_REQUEST_SESSION',
                'project_id' => $projectId,
            ]);

            return ApiResponse::error(
                new ErrorData(
                    code: 'internal_error',
                    message: 'The task collection session context is unavailable.',
                ),
                500,
                $correlationId,
            );
        }

        $query = ListQuery::fromRequest(
            request: $request,
            allowedSorts: ['updatedAt', 'dueAt', 'priority', 'createdAt'],
            defaultSort: 'updatedAt',
            defaultDirections: [
                'updatedAt' => 'desc',
                'dueAt' => 'asc',
                'priority' => 'desc',
                'createdAt' => 'desc',
            ],
            allowedStatuses: array_map(
                static fn (TaskStatus $status): string => $status->value,
                TaskStatus::cases(),
            ),
            allowedPriorities: array_map(
                static fn (TaskPriority $priority): string => $priority->value,
                TaskPriority::cases(),
            ),
        );

        $lookup = $this->taskService->listForProject($session, $projectId, $query, $correlationId);
        $request->attributes->set(TasksCollectionAuditMiddleware::RESOLUTION_ATTRIBUTE, $lookup->resolution);

        if ($lookup->collection === null) {
            return ApiResponse::error(
                new ErrorData(
                    code: 'project_not_found',
                    message: 'The requested project could not be found.',
                    reason: 'PROJECT_NOT_FOUND',
                ),
                404,
                $correlationId,
            );
        }

        $pagination = PaginationMeta::fromTotal($query->page, $query->perPage, $lookup->collection->total);
        $request->attributes->set(TasksCollectionAuditMiddleware::QUERY_ATTRIBUTE, $query);
        $request->attributes->set(TasksCollectionAuditMiddleware::RESULT_COUNT_ATTRIBUTE, count($lookup->collection->items));
        $request->attributes->set(TasksCollectionAuditMiddleware::TOTAL_ATTRIBUTE, $lookup->collection->total);

        return ApiResponse::collection(
            TaskCollectionData::fromDomain($lookup->collection, $pagination, $query),
            correlationId: $correlationId,
        );
    }

    public function updateStatus(
        UpdateTaskStatusRequest $request,
        string $projectId,
        string $taskId,
    ): JsonResponse {
        $correlationId = $this->resolveCorrelationId($request) ?? (string) Str::uuid();
        $session = $request->attributes->get(SessionMiddleware::REQUEST_ATTRIBUTE);

        if (! $session instanceof SessionData) {
            $this->logger->error('tasks.status.internal_error', [
                'correlation_id' => $correlationId,
                'reason' => 'MISSING_REQUEST_SESSION',
                'project_id' => $projectId,
                'task_id' => $taskId,
            ]);

            return ApiResponse::error(
                new ErrorData(
                    code: 'internal_error',
                    message: 'The task status session context is unavailable.',
                ),
                500,
                $correlationId,
            );
        }

        $command = new UpdateTaskStatusCommand(
            projectId: $projectId,
            taskId: $taskId,
            targetStatus: TaskStatus::from((string) $request->input('status')),
            metadata: new WriteCommandMetadata(
                actorId: $session->userSub,
                actorEmail: $session->userEmail,
                correlationId: $correlationId,
                idempotencyKey: (string) $request->input('idempotencyKey'),
                expectedVersion: (int) $request->integer('expectedVersion'),
            ),
        );

        $execution = $this->taskMutationHandler->updateStatus($command);

        if ($execution->state === 'not_found') {
            return ApiResponse::error(
                new ErrorData(
                    code: 'project_not_found',
                    message: 'The requested project could not be found.',
                    reason: 'PROJECT_NOT_FOUND',
                ),
                404,
                $correlationId,
            );
        }

        if ($execution->state === 'validation_failed') {
            return ApiResponse::error(
                new ErrorData(
                    code: 'validation_error',
                    message: 'The task status mutation could not be applied.',
                    reason: 'VALIDATION_ERROR',
                    details: [
                        'violations' => array_map(
                            static fn ($violation): array => [
                                'code' => $violation->code,
                                'message' => $violation->message,
                                'field' => $violation->field,
                            ],
                            $execution->violations,
                        ),
                    ],
                ),
                422,
                $correlationId,
            );
        }

        if ($execution->state === 'conflict') {
            return ApiResponse::error(
                new ErrorData(
                    code: 'conflict',
                    message: $this->conflictMessage($execution->reason),
                    reason: $execution->reason,
                    details: $execution->details,
                ),
                409,
                $correlationId,
            );
        }

        if (! $execution->successful()) {
            return ApiResponse::error(
                new ErrorData(
                    code: 'internal_error',
                    message: 'The task status mutation could not be completed.',
                ),
                500,
                $correlationId,
            );
        }

        $response = ApiResponse::success(
            TaskStatusMutationData::fromDomain($execution->result),
            correlationId: $correlationId,
        );

        if ($execution->replayed) {
            $response->headers->set('X-Idempotent-Replay', 'true');
        }

        return $response;
    }

    public function store(CreateTaskRequest $request, string $projectId): JsonResponse
    {
        $correlationId = $this->resolveCorrelationId($request) ?? (string) Str::uuid();
        $session = $request->attributes->get(SessionMiddleware::REQUEST_ATTRIBUTE);

        if (! $session instanceof SessionData) {
            $this->logger->error('tasks.create.internal_error', [
                'correlation_id' => $correlationId,
                'reason' => 'MISSING_REQUEST_SESSION',
                'project_id' => $projectId,
            ]);

            return ApiResponse::error(
                new ErrorData(
                    code: 'internal_error',
                    message: 'The task creation session context is unavailable.',
                ),
                500,
                $correlationId,
            );
        }

        $command = new CreateTaskCommand(
            projectId: $projectId,
            title: (string) $request->input('title'),
            description: (string) $request->input('description'),
            priority: TaskPriority::from((string) $request->input('priority')),
            assigneeId: $session->userSub,
            assigneeEmail: $session->userEmail,
            actorRole: \App\Domain\ClientPortal\Enums\TaskActorRole::Assignee,
            dueAt: null,
            metadata: new WriteCommandMetadata(
                actorId: $session->userSub,
                actorEmail: $session->userEmail,
                correlationId: $correlationId,
                idempotencyKey: (string) $request->input('idempotencyKey'),
            ),
        );

        $execution = $this->taskCreationHandler->create($command);

        if ($execution->state === 'not_found') {
            return ApiResponse::error(
                new ErrorData(
                    code: 'project_not_found',
                    message: 'The requested project could not be found.',
                    reason: 'PROJECT_NOT_FOUND',
                ),
                404,
                $correlationId,
            );
        }

        if ($execution->state === 'validation_failed') {
            return ApiResponse::error(
                new ErrorData(
                    code: 'validation_error',
                    message: 'The task creation could not be applied.',
                    reason: 'VALIDATION_ERROR',
                    details: [
                        'violations' => array_map(
                            static fn ($violation): array => [
                                'code' => $violation->code,
                                'message' => $violation->message,
                                'field' => $violation->field,
                            ],
                            $execution->violations,
                        ),
                    ],
                ),
                422,
                $correlationId,
            );
        }

        if ($execution->state === 'conflict') {
            return ApiResponse::error(
                new ErrorData(
                    code: 'conflict',
                    message: $this->conflictMessage($execution->reason),
                    reason: $execution->reason,
                    details: $execution->details,
                ),
                409,
                $correlationId,
            );
        }

        if (! $execution->successful()) {
            return ApiResponse::error(
                new ErrorData(
                    code: 'internal_error',
                    message: 'The task creation could not be completed.',
                ),
                500,
                $correlationId,
            );
        }

        $response = ApiResponse::success(
            TaskCreationData::fromDomain($execution->result),
            201,
            $correlationId,
        );

        if ($execution->replayed) {
            $response->headers->set('X-Idempotent-Replay', 'true');
        }

        return $response;
    }

    private function resolveCorrelationId(Request $request): ?string
    {
        $attributeCorrelationId = $request->attributes->get(SessionMiddleware::CORRELATION_ID_ATTRIBUTE);

        if (is_string($attributeCorrelationId) && $attributeCorrelationId !== '') {
            return $attributeCorrelationId;
        }

        $headerCorrelationId = $request->header('X-Correlation-ID');

        return is_string($headerCorrelationId) && $headerCorrelationId !== ''
            ? $headerCorrelationId
            : null;
    }

    private function conflictMessage(?string $reason): string
    {
        return match ($reason) {
            'STALE_WRITE' => 'The task workflow was modified by another request.',
            'IDEMPOTENCY_KEY_REUSED' => 'The idempotency key has already been used for a different request.',
            'IDEMPOTENCY_IN_PROGRESS' => 'The task mutation is already being processed.',
            default => 'The task mutation could not be completed due to a conflict.',
        };
    }
}