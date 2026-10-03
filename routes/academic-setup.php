<?php

use App\Http\Controllers\AcademicSetupController;
use Illuminate\Support\Facades\Route;

Route::prefix('admin/setup')->middleware('role:admin')->group(function () {
    Route::get('/', [AcademicSetupController::class, 'show'])->name('academic-setup');
    Route::get('/template', [AcademicSetupController::class, 'template']);
    Route::post('/duplicate', [AcademicSetupController::class, 'duplicate'])->block(120, 10);
    Route::post('/steps/{step}', [AcademicSetupController::class, 'save'])->where('step', '[1-5]')->block(120, 10);
    Route::post('/create', [AcademicSetupController::class, 'create'])->block(120, 10);
    Route::post('/restart', [AcademicSetupController::class, 'restart'])->block(120, 10);
});
