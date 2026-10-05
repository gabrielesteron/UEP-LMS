<?php

use App\Http\Controllers\SuperAdminController;
use Illuminate\Support\Facades\Route;

Route::prefix('super-admin')->middleware('role:super_admin')->group(function () {
    Route::redirect('/dashboard', '/dashboard');
    Route::get('/admins', [SuperAdminController::class, 'admins']);
    Route::get('/roles', [SuperAdminController::class, 'roles']);
    Route::get('/settings', [SuperAdminController::class, 'settings']);
    Route::put('/settings', [SuperAdminController::class, 'saveSettings']);
    Route::get('/features', [SuperAdminController::class, 'features']);
    Route::put('/features', [SuperAdminController::class, 'saveFeatures']);
    Route::get('/audit', [SuperAdminController::class, 'audit']);
    Route::get('/information', [SuperAdminController::class, 'information']);
    Route::post('/users/{id}/restore', [SuperAdminController::class, 'restore'])->whereNumber('id');
});
