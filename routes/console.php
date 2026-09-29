<?php

use App\Http\Controllers\QuizController;
use App\Models\Assignment;
use App\Models\DeadlineReminder;
use App\Models\QuizAttempt;
use App\Models\User;
use App\Notifications\PortalNotice;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

Artisan::command('lms:create-admin', function () {
    $name = $this->ask('Administrator name');
    $email = $this->ask('Email');
    $password = $this->secret('Password (12+ characters, mixed case, number)');
    $validation = Validator::make(compact('name', 'email', 'password'), ['name' => 'required|max:120', 'email' => 'required|email|unique:users,email', 'password' => ['required', Password::min(12)->mixedCase()->numbers()]]);
    if ($validation->fails()) {
        foreach ($validation->errors()->all() as $error) {
            $this->error($error);
        }

return 1;
    }
    User::create(compact('name', 'email', 'password') + ['role' => 'admin', 'status' => 'active', 'email_verified_at' => now()]);
    $this->info('Administrator created.');
})->purpose('Create a trusted administrator interactively without exposing a password in shell history');
Artisan::command('lms:deadlines', function () {
    foreach (Assignment::where('status', 'published')->whereBetween('due_at', [now(), now()->addDay()])->get() as $assignment) {
        foreach ($assignment->classroom->enrollments()->with('student.user')->get() as $enrollment) {
            $user = $enrollment->student->user;
            if (! $user || $user->status !== 'active' || $assignment->submissions()->where('student_id', $enrollment->student_id)->exists()) {
                continue;
            }
            DB::transaction(function () use ($assignment, $user) {
                $reminder = DeadlineReminder::firstOrCreate(['assignment_id' => $assignment->id, 'user_id' => $user->id]);
                if ($reminder->wasRecentlyCreated) {
                    $user->notify(new PortalNotice('Due within 24 hours', $assignment->title, '/assignments/'.$assignment->id));
                }
            });
        }
    }
    $this->info('Deadline notifications checked.');
})->purpose('Send one in-app reminder per student and assignment');
Artisan::command('lms:expire-quizzes', function () {
    foreach (QuizAttempt::whereNull('completed_at')->where('expires_at', '<', now())->get() as $attempt) {
        app(QuizController::class)->finish($attempt, []);
    }
    $this->info('Expired attempts finalized.');
});
Schedule::command('lms:deadlines')->hourly()->withoutOverlapping();
Schedule::command('lms:expire-quizzes')->everyMinute()->withoutOverlapping();
