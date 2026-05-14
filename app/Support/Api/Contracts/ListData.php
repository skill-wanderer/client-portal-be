<?php

namespace App\Support\Api\Contracts;

use App\Support\Api\Query\ListQuery;

final class ListData implements ApiDataContract
{
    /**
     * @param array<int, ApiDataContract|array<string, mixed>> $items
     */
    public function __construct(
        public readonly array $items,
        public readonly ?PaginationMeta $pagination = null,
        public readonly ?ListQuery $query = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $payload = [
            'items' => array_map(
                fn (ApiDataContract|array $item): array => $item instanceof ApiDataContract
                    ? $item->toArray()
                    : $item,
                $this->items,
            ),
        ];

        $meta = [];

        if ($this->pagination instanceof PaginationMeta) {
            $meta['pagination'] = $this->pagination->toArray();
        }

        if ($this->query instanceof ListQuery) {
            $meta['query'] = $this->query->toArray();
        }

        if ($meta !== []) {
            $payload['meta'] = $meta;
        }

        return $payload;
    }
}