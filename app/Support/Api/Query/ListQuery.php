<?php

namespace App\Support\Api\Query;

use Illuminate\Http\Request;

final class ListQuery
{
    /**
     * @param array<int, string> $allowedSorts
     */
    public static function fromRequest(
        Request $request,
        array $allowedSorts = [],
        int $defaultPerPage = 20,
        int $maxPerPage = 100,
        ?string $defaultSort = null,
        array $defaultDirections = [],
        array $allowedStatuses = [],
        array $allowedPriorities = [],
    ): self {
        $page = max(1, self::normalizePositiveInt($request->query('page'), 1));
        $perPageInput = $request->query('per_page', $request->query('perPage', $defaultPerPage));
        $perPage = min(max(1, self::normalizePositiveInt($perPageInput, $defaultPerPage)), max(1, $maxPerPage));
        $sortMap = self::canonicalMap($allowedSorts);
        $rawSort = self::normalizeString($request->query('sort'));
        $resolvedSort = $rawSort !== null && $sortMap !== []
            ? ($sortMap[strtolower($rawSort)] ?? null)
            : $rawSort;
        $resolvedSort ??= $defaultSort ?? ($allowedSorts[0] ?? null);
        $defaultDirection = $resolvedSort !== null && array_key_exists($resolvedSort, $defaultDirections)
            ? strtolower((string) $defaultDirections[$resolvedSort])
            : 'desc';
        $rawDirection = strtolower((string) $request->query('direction', ''));
        $direction = $resolvedSort !== null
            && $rawSort !== null
            && isset($sortMap[strtolower($rawSort)])
            && in_array($rawDirection, ['asc', 'desc'], true)
            ? $rawDirection
            : $defaultDirection;
        $statusMap = self::canonicalMap($allowedStatuses);
        $priorityMap = self::canonicalMap($allowedPriorities);
        $search = self::normalizeString($request->query('search'));
        $rawStatus = self::normalizeString($request->query('status'));
        $status = $rawStatus !== null && $statusMap !== []
            ? ($statusMap[strtolower($rawStatus)] ?? null)
            : $rawStatus;
        $rawPriority = self::normalizeString($request->query('priority'));
        $priority = $rawPriority !== null && $priorityMap !== []
            ? ($priorityMap[strtolower($rawPriority)] ?? null)
            : $rawPriority;

        return new self(
            page: $page,
            perPage: $perPage,
            sort: $resolvedSort,
            direction: $direction,
            search: $search,
            status: $status,
            priority: $priority,
            includePriority: $allowedPriorities !== [],
        );
    }

    public function __construct(
        public readonly int $page = 1,
        public readonly int $perPage = 20,
        public readonly ?string $sort = null,
        public readonly string $direction = 'desc',
        public readonly ?string $search = null,
        public readonly ?string $status = null,
        public readonly ?string $priority = null,
        private readonly bool $includePriority = false,
    ) {
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->perPage;
    }

    /**
     * @return array<string, int|string|null>
     */
    public function toArray(): array
    {
        $payload = [
            'page' => $this->page,
            'perPage' => $this->perPage,
            'sort' => $this->sort,
            'direction' => $this->direction,
            'search' => $this->search,
            'status' => $this->status,
        ];

        if ($this->includePriority) {
            $payload['priority'] = $this->priority;
        }

        return $payload;
    }

    /**
     * @param array<int, string> $values
     * @return array<string, string>
     */
    private static function canonicalMap(array $values): array
    {
        $map = [];

        foreach ($values as $value) {
            $map[strtolower($value)] = $value;
        }

        return $map;
    }

    private static function normalizePositiveInt(mixed $value, int $default): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^\d+$/', $value) === 1) {
            return (int) $value;
        }

        return $default;
    }

    private static function normalizeString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = trim($value);

        return $normalized === '' ? null : $normalized;
    }
}