<?php

use App\Http\Controllers\Api\V1\ApiKeyController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\DeviceController;
use App\Http\Controllers\Api\V1\DeviceMessageController;
use App\Http\Controllers\Api\V1\MessageController;
use App\Http\Controllers\Api\V1\ProjectController;
use App\Http\Controllers\Api\V1\UserAdminController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware('https.only')->group(function (): void {
    Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:auth');

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('/me', [AuthController::class, 'me']);
        Route::delete('/auth/token', [AuthController::class, 'logout']);
        Route::middleware('role.admin')->group(function (): void {
            Route::get('/users', [UserAdminController::class, 'index']);
            Route::patch('/users/{user}', [UserAdminController::class, 'update']);
        });
        Route::apiResource('projects', ProjectController::class)->only(['index', 'store', 'show']);
        Route::post('/projects/{project}/api-keys', [ApiKeyController::class, 'store']);
        Route::delete('/projects/{project}/api-keys/{apiKey}', [ApiKeyController::class, 'destroy']);
        Route::get('/projects/{project}/devices', [DeviceController::class, 'index']);
        Route::post('/projects/{project}/devices', [DeviceController::class, 'store']);
        Route::delete('/projects/{project}/devices/{device}', [DeviceController::class, 'revoke']);
    });

    Route::middleware(['api-key', 'throttle:messages'])->group(function (): void {
        Route::post('/messages', [MessageController::class, 'store']);
        Route::get('/messages/{messageId}', [MessageController::class, 'show']);
    });

    Route::prefix('devices')->middleware(['device.auth', 'throttle:devices'])->group(function (): void {
        Route::post('/heartbeat', [DeviceController::class, 'heartbeat']);
        Route::get('/messages/claim', [DeviceMessageController::class, 'claim']);
        Route::post('/messages/{messageId}/acknowledge', [DeviceMessageController::class, 'acknowledge']);
        Route::post('/messages/{messageId}/report', [DeviceMessageController::class, 'report']);
    });
});
