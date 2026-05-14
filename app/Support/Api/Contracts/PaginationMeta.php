<?php

namespace App\Support\Api\Contracts;

final class PaginationMeta implements ApiDataContract
{
    public function __construct(
        public readonly int $page,
        public readonly int $perPage,
        public readonly int $total,
        public readonly int $totalPages,
    ) {
    }

    public static function fromTotal(int $page, int $perPage, int $total): self
    {
        $normalizedPage = max(1, $page);
        $normalizedPerPage = max(1, $perPage);
        $normalizedTotal = max(0, $total);

        return new self(
            page: $normalizedPage,
            perPage: $normalizedPerPage,
            total: $normalizedTotal,
            totalPages: max(1, (int) ceil($normalizedTotal / $normalizedPerPage)),
        );
    }

    /**
     * @return array<string, int>
     */
    public function toArray(): array
    {
        return [
            'page' => $this->page,
            'perPage' => $this->perPage,
            'total' => $this->total,
            'totalPages' => $this->totalPages,
        ];
    }
}