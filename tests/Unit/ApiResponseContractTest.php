<?php

namespace Tests\Unit;

use App\Support\Api\ApiResponse;
use App\Support\Api\Contracts\ListData;
use App\Support\Api\Contracts\PaginationMeta;
use App\Support\Api\Query\ListQuery;
use Tests\TestCase;

class ApiResponseContractTest extends TestCase
{
    public function test_collection_response_serializes_items_and_meta(): void
    {
        $response = ApiResponse::collection(
            new ListData(
                items: [[
                    'id' => 'project-1',
                    'name' => 'Starter Project',
                    'status' => 'active',
                ]],
                pagination: PaginationMeta::fromTotal(page: 2, perPage: 25, total: 40),
                query: new ListQuery(
                    page: 2,
                    perPage: 25,
                    sort: 'updatedAt',
                    direction: 'desc',
                    search: null,
                    status: null,
                ),
            ),
            correlationId: 'corr-123',
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('corr-123', $response->headers->get('X-Correlation-ID'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertSame([
            'success' => true,
            'data' => [
                'items' => [[
                    'id' => 'project-1',
                    'name' => 'Starter Project',
                    'status' => 'active',
                ]],
                'meta' => [
                    'pagination' => [
                        'page' => 2,
                        'perPage' => 25,
                        'total' => 40,
                        'totalPages' => 2,
                    ],
                    'query' => [
                        'page' => 2,
                        'perPage' => 25,
                        'sort' => 'updatedAt',
                        'direction' => 'desc',
                        'search' => null,
                        'status' => null,
                    ],
                ],
            ],
        ], $response->getData(true));
    }
}