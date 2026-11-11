<?php

use App\Http\Controllers\DashboardAuthController;
use App\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/admin');

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [DashboardAuthController::class, 'create'])->name('login');
    Route::post('/login', [DashboardAuthController::class, 'store'])->middleware('throttle:dashboard-login')->name('login.store');
});

Route::middleware(['auth', 'role.admin'])->prefix('admin')->name('admin.')->group(function (): void {
    Route::get('/', [DashboardController::class, 'index'])->name('index');
    Route::post('/logout', [DashboardAuthController::class, 'destroy'])->name('logout');
    Route::get('/projects', [DashboardController::class, 'projects'])->name('projects');
    Route::post('/projects', [DashboardController::class, 'storeProject'])->name('projects.store');
    Route::patch('/projects/{project}', [DashboardController::class, 'updateProject'])->name('projects.update');
    Route::get('/users', [DashboardController::class, 'users'])->name('users');
    Route::patch('/users/{user}', [DashboardController::class, 'updateUser'])->name('users.update');
    Route::get('/devices', [DashboardController::class, 'devices'])->name('devices');
    Route::post('/projects/{project}/devices', [DashboardController::class, 'storeDevice'])->name('devices.store');
    Route::delete('/projects/{project}/devices/{device}', [DashboardController::class, 'revokeDevice'])->name('devices.revoke');
    Route::get('/keys', [DashboardController::class, 'keys'])->name('keys');
    Route::post('/projects/{project}/keys', [DashboardController::class, 'storeKey'])->name('keys.store');
    Route::delete('/projects/{project}/keys/{apiKey}', [DashboardController::class, 'revokeKey'])->name('keys.revoke');
    Route::get('/messages', [DashboardController::class, 'messages'])->name('messages');
    Route::get('/usage', [DashboardController::class, 'usage'])->name('usage');
    Route::get('/audit', [DashboardController::class, 'audit'])->name('audit');
    Route::get('/diagnostics', [DashboardController::class, 'diagnostics'])->name('diagnostics');
});
