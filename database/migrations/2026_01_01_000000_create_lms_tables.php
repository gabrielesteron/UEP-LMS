<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->string('role')->default('student')->index();
            $t->string('status')->default('inactive')->index();
            $t->string('profile_picture')->nullable();
            $t->softDeletes();
        });
        Schema::create('academic_years', function (Blueprint $t) {
            $t->id();
            $t->string('name')->unique();
            $t->date('starts_on');
            $t->date('ends_on');
            $t->timestamps();
        });
        Schema::create('programs', function (Blueprint $t) {
            $t->id();
            $t->string('code')->unique();
            $t->string('name');
            $t->timestamps();
        });
        Schema::create('year_levels', function (Blueprint $t) {
            $t->id();
            $t->string('name')->unique();
            $t->unsignedTinyInteger('level')->unique();
            $t->timestamps();
        });
        Schema::create('blocks', function (Blueprint $t) {
            $t->id();
            $t->foreignId('academic_year_id')->constrained('academic_years')->restrictOnDelete();
            $t->foreignId('program_id')->constrained('programs')->restrictOnDelete();
            $t->foreignId('year_level_id')->constrained('year_levels')->restrictOnDelete();
            $t->string('name');
            $t->unsignedTinyInteger('semester');
            $t->unique([
                0 => 'academic_year_id',
                1 => 'program_id',
                2 => 'year_level_id',
                3 => 'semester',
                4 => 'name',
            ], 'blocks_composite_unique');
            $t->timestamps();
        });
        Schema::create('students', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained('users')->restrictOnDelete()->unique();
            $t->string('student_number')->unique();
            $t->foreignId('block_id')->constrained('blocks')->restrictOnDelete();
            $t->timestamps();
        });
        Schema::create('teachers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained('users')->restrictOnDelete()->unique();
            $t->string('employee_number')->unique();
            $t->timestamps();
        });
        Schema::create('subjects', function (Blueprint $t) {
            $t->id();
            $t->string('code')->unique();
            $t->string('name');
            $t->text('description')->nullable();
            $t->unsignedTinyInteger('units');
            $t->string('status');
            $t->timestamps();
        });
        Schema::create('teacher_assignments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('teacher_id')->constrained('teachers')->restrictOnDelete();
            $t->foreignId('block_id')->constrained('blocks')->restrictOnDelete();
            $t->foreignId('subject_id')->constrained('subjects')->restrictOnDelete();
            $t->unique([
                0 => 'block_id',
                1 => 'subject_id',
            ], 'teacher_assignments_composite_unique');
            $t->timestamps();
        });
        Schema::create('enrollments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('student_id')->constrained('students')->restrictOnDelete();
            $t->foreignId('teacher_assignment_id')->constrained('teacher_assignments')->restrictOnDelete();
            $t->unique([
                0 => 'student_id',
                1 => 'teacher_assignment_id',
            ], 'enrollments_composite_unique');
            $t->timestamps();
        });
        Schema::create('class_schedules', function (Blueprint $t) {
            $t->id();
            $t->foreignId('teacher_assignment_id')->constrained('teacher_assignments')->restrictOnDelete();
            $t->unsignedTinyInteger('day');
            $t->time('start_time');
            $t->time('end_time');
            $t->string('room');
            $t->timestamps();
        });
        Schema::create('lessons', function (Blueprint $t) {
            $t->id();
            $t->foreignId('teacher_assignment_id')->constrained('teacher_assignments')->restrictOnDelete();
            $t->string('title');
            $t->text('description')->nullable();
            $t->longText('content');
            $t->unsignedInteger('position');
            $t->string('status');
            $t->timestamps();
        });
        Schema::create('learning_materials', function (Blueprint $t) {
            $t->id();
            $t->foreignId('teacher_assignment_id')->constrained('teacher_assignments')->restrictOnDelete();
            $t->string('title');
            $t->text('description')->nullable();
            $t->string('path');
            $t->string('original_name');
            $t->string('status');
            $t->timestamps();
        });
        Schema::create('assignments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('teacher_assignment_id')->constrained('teacher_assignments')->restrictOnDelete();
            $t->string('title');
            $t->text('description')->nullable();
            $t->longText('instructions');
            $t->dateTime('due_at')->index();
            $t->decimal('total_points', 8, 2);
            $t->boolean('allow_text');
            $t->string('path')->nullable();
            $t->string('original_name')->nullable();
            $t->string('status');
            $t->timestamps();
        });
        Schema::create('assignment_submissions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('assignment_id')->constrained('assignments')->restrictOnDelete();
            $t->foreignId('student_id')->constrained('students')->restrictOnDelete();
            $t->unsignedInteger('version');
            $t->longText('answer')->nullable();
            $t->string('path')->nullable();
            $t->string('original_name')->nullable();
            $t->dateTime('submitted_at');
            $t->boolean('is_late');
            $t->string('status');
            $t->decimal('score', 8, 2)->nullable();
            $t->text('feedback')->nullable();
            $t->dateTime('graded_at')->nullable();
            $t->unique([
                0 => 'assignment_id',
                1 => 'student_id',
                2 => 'version',
            ], 'assignment_submissions_composite_unique');
            $t->timestamps();
        });
        Schema::create('quizzes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('teacher_assignment_id')->constrained('teacher_assignments')->restrictOnDelete();
            $t->string('title');
            $t->text('description')->nullable();
            $t->unsignedInteger('time_limit');
            $t->unsignedTinyInteger('max_attempts');
            $t->dateTime('available_from');
            $t->dateTime('available_until');
            $t->string('status');
            $t->timestamps();
        });
        Schema::create('quiz_questions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('quiz_id')->constrained('quizzes')->restrictOnDelete();
            $t->text('question');
            $t->string('option_a');
            $t->string('option_b');
            $t->string('option_c');
            $t->string('option_d');
            $t->string('correct_answer');
            $t->decimal('points', 8, 2);
            $t->timestamps();
        });
        Schema::create('quiz_attempts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('quiz_id')->constrained('quizzes')->restrictOnDelete();
            $t->foreignId('student_id')->constrained('students')->restrictOnDelete();
            $t->unsignedTinyInteger('attempt_number');
            $t->dateTime('started_at');
            $t->dateTime('expires_at');
            $t->dateTime('completed_at')->nullable();
            $t->decimal('score', 8, 2)->nullable();
            $t->unique([
                0 => 'quiz_id',
                1 => 'student_id',
                2 => 'attempt_number',
            ], 'quiz_attempts_composite_unique');
            $t->timestamps();
        });
        Schema::create('quiz_answers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('quiz_attempt_id')->constrained('quiz_attempts')->restrictOnDelete();
            $t->foreignId('quiz_question_id')->constrained('quiz_questions')->restrictOnDelete();
            $t->string('answer')->nullable();
            $t->decimal('points', 8, 2);
            $t->unique([
                0 => 'quiz_attempt_id',
                1 => 'quiz_question_id',
            ], 'quiz_answers_composite_unique');
            $t->timestamps();
        });
        Schema::create('grades', function (Blueprint $t) {
            $t->id();
            $t->foreignId('teacher_assignment_id')->constrained('teacher_assignments')->restrictOnDelete();
            $t->foreignId('student_id')->constrained('students')->restrictOnDelete();
            $t->string('source_type');
            $t->unsignedBigInteger('source_id');
            $t->string('title');
            $t->decimal('score', 8, 2);
            $t->decimal('total_points', 8, 2);
            $t->unique([
                0 => 'student_id',
                1 => 'source_type',
                2 => 'source_id',
            ], 'grades_composite_unique');
            $t->timestamps();
        });
        Schema::create('attendance_sessions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('teacher_assignment_id')->constrained('teacher_assignments')->restrictOnDelete();
            $t->date('date');
            $t->time('start_time');
            $t->time('end_time');
            $t->unsignedInteger('late_threshold');
            $t->unique([
                0 => 'teacher_assignment_id',
                1 => 'date',
                2 => 'start_time',
            ], 'attendance_sessions_composite_unique');
            $t->timestamps();
        });
        Schema::create('attendance_records', function (Blueprint $t) {
            $t->id();
            $t->foreignId('attendance_session_id')->constrained('attendance_sessions')->restrictOnDelete();
            $t->foreignId('student_id')->constrained('students')->restrictOnDelete();
            $t->string('status');
            $t->unsignedInteger('minutes_late')->nullable();
            $t->text('remarks')->nullable();
            $t->unique([
                0 => 'attendance_session_id',
                1 => 'student_id',
            ], 'attendance_records_composite_unique');
            $t->timestamps();
        });
        Schema::create('attendance_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('attendance_record_id')->constrained('attendance_records')->restrictOnDelete();
            $t->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $t->json('before')->nullable();
            $t->json('after');
            $t->text('reason');
            $t->timestamps();
        });
        Schema::create('excuse_requests', function (Blueprint $t) {
            $t->id();
            $t->foreignId('attendance_record_id')->constrained('attendance_records')->restrictOnDelete()->unique();
            $t->text('reason');
            $t->string('status');
            $t->text('review_note')->nullable();
            $t->timestamps();
        });
        Schema::create('announcements', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $t->foreignId('teacher_assignment_id')->nullable()->constrained('teacher_assignments')->restrictOnDelete();
            $t->foreignId('program_id')->nullable()->constrained('programs')->restrictOnDelete();
            $t->foreignId('block_id')->nullable()->constrained('blocks')->restrictOnDelete();
            $t->string('title');
            $t->text('body');
            $t->timestamps();
        });
        Schema::create('settings', function (Blueprint $t) {
            $t->id();
            $t->string('key')->unique();
            $t->string('value');
            $t->timestamps();
        });
        Schema::create('deadline_reminders', function (Blueprint $t) {
            $t->id();
            $t->foreignId('assignment_id')->constrained('assignments')->restrictOnDelete();
            $t->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $t->unique([
                0 => 'assignment_id',
                1 => 'user_id',
            ], 'deadline_reminders_composite_unique');
            $t->timestamps();
        });
        Schema::create('notifications', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('type');
            $t->morphs('notifiable');
            $t->text('data');
            $t->timestamp('read_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('deadline_reminders');
        Schema::dropIfExists('settings');
        Schema::dropIfExists('announcements');
        Schema::dropIfExists('excuse_requests');
        Schema::dropIfExists('attendance_logs');
        Schema::dropIfExists('attendance_records');
        Schema::dropIfExists('attendance_sessions');
        Schema::dropIfExists('grades');
        Schema::dropIfExists('quiz_answers');
        Schema::dropIfExists('quiz_attempts');
        Schema::dropIfExists('quiz_questions');
        Schema::dropIfExists('quizzes');
        Schema::dropIfExists('assignment_submissions');
        Schema::dropIfExists('assignments');
        Schema::dropIfExists('learning_materials');
        Schema::dropIfExists('lessons');
        Schema::dropIfExists('class_schedules');
        Schema::dropIfExists('enrollments');
        Schema::dropIfExists('teacher_assignments');
        Schema::dropIfExists('subjects');
        Schema::dropIfExists('teachers');
        Schema::dropIfExists('students');
        Schema::dropIfExists('blocks');
        Schema::dropIfExists('year_levels');
        Schema::dropIfExists('programs');
        Schema::dropIfExists('academic_years');
        Schema::table('users', function (Blueprint $t) {
            $t->dropIndex(['role']);
            $t->dropIndex(['status']);
            $t->dropColumn(['role', 'status', 'profile_picture', 'deleted_at']);
        });
    }
};
