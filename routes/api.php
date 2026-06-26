<?php

use App\Http\Controllers\Api\RuntimeTestController;
use App\Http\Middleware\EnsureKeycloakBearerToken;
use App\Security\Keycloak\KeycloakPrincipal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1')->group(function () {
    Route::get('/test-db', [RuntimeTestController::class, 'database']);

    Route::get('/auth/me', function (Request $request) {
        $principal = $request->attributes->get(EnsureKeycloakBearerToken::REQUEST_ATTRIBUTE);

        return response()->json([
            'authenticated' => true,
            'principal' => $principal instanceof KeycloakPrincipal ? $principal->toArray() : null,
        ]);
    })->middleware('keycloak.token');
});
