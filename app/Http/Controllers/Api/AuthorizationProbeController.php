<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class AuthorizationProbeController extends Controller
{
    public function clientUser(): JsonResponse
    {
        return response()->json([
            'success' => true,
        ]);
    }

    public function adminUser(): JsonResponse
    {
        return response()->json([
            'success' => true,
        ]);
    }
}