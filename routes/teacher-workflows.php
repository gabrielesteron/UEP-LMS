<?php

use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\ClassController;
use Illuminate\Support\Facades\Route;

Route::put('/attendance/sessions/{session}/bulk-records', [AttendanceController::class, 'bulkRecord']);
Route::put('/assignments/{assignment}/grades', [ClassController::class, 'bulkGrade']);
