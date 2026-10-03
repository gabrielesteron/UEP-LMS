<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AnnouncementController;
use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ClassController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\QuizController;
use App\Http\Controllers\ReportController;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

Route::redirect('/', '/dashboard');
Route::middleware('guest')->group(function () {
    Route::view('/login', 'auth.login')->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:15,1');
    Route::view('/forgot-password', 'auth.forgot')->name('password.request');
    Route::post('/forgot-password', [AuthController::class, 'forgot'])->middleware('throttle:3,1')->name('password.email');
    Route::get('/reset-password/{token}', fn (Request $r, string $token) => view('auth.reset', compact('token')))->name('password.reset');
    Route::post('/reset-password', [AuthController::class, 'reset'])->middleware('throttle:6,1')->name('password.update');
});
Route::get('/activate/{user}', [AuthController::class, 'activation'])->middleware('signed')->name('activate');
Route::post('/activate/{user}', [AuthController::class, 'activate'])->middleware(['signed', 'throttle:6,1']);
Route::middleware(['auth', 'active'])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::view('/verify-email', 'auth.verify')->name('verification.notice');
    Route::get('/verify-email/{id}/{hash}', function (EmailVerificationRequest $r) {
        $r->fulfill();

        return redirect('/dashboard');
    })->middleware('signed')->name('verification.verify');
    Route::post('/email/verification-notification', function (Request $r) {
        $r->user()->sendEmailVerificationNotification();

        return back()->with('success', 'Verification email sent.');
    })->middleware('throttle:3,1')->name('verification.send');
});
Route::middleware(['auth', 'active', 'verified'])->group(function () {
    require __DIR__.'/teacher-workflows.php';
    require __DIR__.'/academic-setup.php';
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    foreach (['admin', 'teacher', 'student'] as $role) {
        Route::redirect('/'.$role.'/dashboard', '/dashboard')->middleware('role:'.$role);
    }
    Route::get('/classes', [DashboardController::class, 'classes']);
    Route::get('/schedule', [DashboardController::class, 'schedule']);
    Route::get('/search', [DashboardController::class, 'search']);
    Route::get('/profile', [AuthController::class, 'profile']);
    Route::put('/profile', [AuthController::class, 'updateProfile']);
    Route::put('/profile/password', [AuthController::class, 'changePassword']);
    Route::get('/profile/photo', function (Request $r) {
        abort_unless($r->user()->profile_picture, 404);

        return Storage::disk('local')->response($r->user()->profile_picture);
    });
    Route::get('/notifications', fn (Request $r) => view('notifications', ['notifications' => $r->user()->notifications()->paginate(20)]));
    Route::post('/notifications/read', function (Request $r) {
        $r->user()->unreadNotifications()->update(['read_at' => now()]);

        return back()->with('success', 'Notifications marked as read.');
    });
    Route::get('/classes/{classroom}', [ClassController::class, 'show']);
    Route::get('/classes/{classroom}/content/{kind}/create', [ClassController::class, 'form']);
    Route::get('/classes/{classroom}/content/{kind}/{id}/edit', [ClassController::class, 'form'])->whereNumber('id');
    Route::post('/classes/{classroom}/content/{kind}', [ClassController::class, 'save']);
    Route::put('/classes/{classroom}/content/{kind}/{id}', [ClassController::class, 'save'])->whereNumber('id');
    Route::delete('/classes/{classroom}/content/{kind}/{id}', [ClassController::class, 'delete'])->whereNumber('id');
    Route::get('/assignments/{assignment}', [ClassController::class, 'assignment']);
    Route::post('/assignments/{assignment}/submit', [ClassController::class, 'submit']);
    Route::put('/submissions/{submission}/grade', [ClassController::class, 'grade']);
    Route::get('/files/{kind}/{id}', [ClassController::class, 'download'])->whereNumber('id');
    Route::get('/quizzes/{quiz}', [QuizController::class, 'show']);
    Route::post('/quizzes/{quiz}/questions', [QuizController::class, 'question']);
    Route::put('/quizzes/{quiz}/questions/{id}', [QuizController::class, 'question'])->whereNumber('id');
    Route::delete('/quizzes/{quiz}/questions/{id}', [QuizController::class, 'deleteQuestion'])->whereNumber('id');
    Route::post('/quizzes/{quiz}/start', [QuizController::class, 'start']);
    Route::get('/attempts/{attempt}', [QuizController::class, 'attempt']);
    Route::post('/attempts/{attempt}', [QuizController::class, 'submit']);
    Route::post('/classes/{classroom}/attendance', [AttendanceController::class, 'create']);
    Route::get('/attendance/sessions/{session}', [AttendanceController::class, 'show']);
    Route::post('/attendance/sessions/{session}/records', [AttendanceController::class, 'record']);
    Route::put('/attendance/sessions/{session}/records/{studentId}', [AttendanceController::class, 'record'])->whereNumber('studentId');
    Route::post('/attendance/{record}/excuse', [AttendanceController::class, 'excuse']);
    Route::put('/excuses/{excuse}', [AttendanceController::class, 'review']);
    Route::get('/announcements', [AnnouncementController::class, 'index']);
    Route::post('/announcements', [AnnouncementController::class, 'save']);
    Route::put('/announcements/{id}', [AnnouncementController::class, 'save'])->whereNumber('id');
    Route::delete('/announcements/{announcement}', [AnnouncementController::class, 'delete']);
    Route::get('/reports/{kind}', [ReportController::class, 'index']);
    Route::get('/classes/{classroom}/gradebook', [ReportController::class, 'gradebook']);
    Route::prefix('admin')->middleware('role:admin')->group(function () {
        Route::get('/settings', [AdminController::class, 'settings']);
        Route::put('/settings', [AdminController::class, 'saveSettings']);
        Route::post('/users/{user}/invite', [AdminController::class, 'invite'])->middleware('throttle:10,1');
        Route::get('/manage/{resource}', [AdminController::class, 'index']);
        Route::get('/manage/{resource}/create', [AdminController::class, 'form']);
        Route::get('/manage/{resource}/{id}/edit', [AdminController::class, 'form'])->whereNumber('id');
        Route::post('/manage/{resource}', [AdminController::class, 'save']);
        Route::put('/manage/{resource}/{id}', [AdminController::class, 'save'])->whereNumber('id');
        Route::delete('/manage/{resource}/{id}', [AdminController::class, 'delete'])->whereNumber('id');
    });
});
