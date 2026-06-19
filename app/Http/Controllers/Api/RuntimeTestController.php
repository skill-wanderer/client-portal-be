<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

final class RuntimeTestController
{
    public function database(): JsonResponse
    {
        try {
            DB::connection()->select('select 1');
        } catch (\Throwable) {
            return response()->json([
                'status' => 'error',
                'database' => 'unavailable',
            ], 503);
        }

        return response()->json([
            'status' => 'ok',
            'database' => 'connected',
        ]);
    }
}
