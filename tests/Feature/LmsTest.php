<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSession;
use App\Models\Block;
use App\Models\LearningMaterial;
use App\Models\Lesson;
use App\Models\Program;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\Subject;
use App\Models\TeacherAssignment;
use App\Models\User;
use App\Notifications\Invitation;
use App\Services\AttendanceService;
use App\Services\Catalog;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class LmsTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function user(string $email = 'student@example.com'): User
    {
        return User::where('email', $email)->firstOrFail();
    }

    private function teacher(): User
    {
        return $this->user('teacher@example.com');
    }

    private function admin(): User
    {
        return $this->user('admin@example.com');
    }

    private function attendance(?User $student = null, int $days = 0): AttendanceSession
    {
        $session = AttendanceSession::create(['teacher_assignment_id' => 1, 'date' => today()->subDays($days), 'start_time' => '15:00', 'end_time' => '16:00', 'late_threshold' => 15]);

        return $session;
    }

    public function test_login_logout_and_invalid_credentials(): void
    {
        $this->get('/login')->assertOk();
        $this->post('/login', ['email' => 'student@example.com', 'password' => 'wrong'])->assertSessionHasErrors('email');
        $this->post('/login', ['email' => 'student@example.com', 'password' => 'CampusDemo!2026'])->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($this->user());
        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_login_throttles_and_suspended_accounts_are_denied(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $response = $this->post('/login', ['email' => 'student@example.com', 'password' => 'wrong']);
        }
        $response->assertSessionHasErrors('email');
        $user = $this->teacher();
        $user->update(['status' => 'suspended']);
        $this->actingAs($user)->get('/dashboard')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_unverified_user_must_verify_email(): void
    {
        $user = $this->user();
        $user->update(['email_verified_at' => null]);
        $this->actingAs($user)->get('/dashboard')->assertRedirect('/verify-email');
        $url = URL::temporarySignedRoute('verification.verify', now()->addHour(), ['id' => $user->id, 'hash' => sha1($user->email)]);
        $this->get($url)->assertRedirect('/dashboard');
        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_invitation_activates_once_and_rejects_tampering(): void
    {
        Notification::fake();
        $this->actingAs($this->admin())->post('/admin/manage/users', ['name' => 'Invited Learner', 'email' => 'invite@example.com', 'role' => 'student', 'status' => 'active'])->assertRedirect('/admin/manage/users');
        $user = User::where('email', 'invite@example.com')->firstOrFail();
        $this->assertSame('inactive', $user->status);
        Notification::assertSentTo($user, Invitation::class);
        $url = Notification::sent($user, Invitation::class)->first()->toMail($user)->actionUrl;
        $this->get($url)->assertOk();
        $this->get($url.'x')->assertStatus(410)->assertSee('Activation link unavailable');
        $this->post($url, ['password' => 'ActivatedPass123', 'password_confirmation' => 'ActivatedPass123'])->assertRedirect('/login');
        $this->assertGuest();
        $this->assertSame('active', $user->fresh()->status);
        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->get('/login')->assertOk();
        $this->post('/login', ['email' => $user->email, 'password' => 'ActivatedPass123'])->assertRedirect('/dashboard');
        $this->get('/dashboard')->assertOk();
        $this->get($url)->assertStatus(410);
    }

    public function test_resending_invitation_explains_why_an_older_link_no_longer_works(): void
    {
        Notification::fake();
        $this->actingAs($this->admin())->post('/admin/manage/users', ['name' => 'Invited Learner', 'email' => 'invite@example.com', 'role' => 'student', 'status' => 'active'])->assertRedirect('/admin/manage/users');
        $user = User::where('email', 'invite@example.com')->firstOrFail();
        $firstUrl = Notification::sent($user, Invitation::class)->first()->toMail($user)->actionUrl;

        $this->post('/admin/users/'.$user->id.'/invite')->assertRedirect();
        $latestUrl = Notification::sent($user, Invitation::class)->last()->toMail($user)->actionUrl;

        $this->get($firstUrl)->assertStatus(410)->assertSee('latest email');
        $this->get($latestUrl)->assertOk();
        $this->post($latestUrl, ['password' => 'ActivatedPass123', 'password_confirmation' => 'ActivatedPass123'])->assertRedirect('/login');
        $this->assertSame('active', $user->fresh()->status);
    }

    public function test_password_reset_works(): void
    {
        Notification::fake();
        $user = $this->user();
        $this->post('/forgot-password', ['email' => $user->email])->assertSessionHas('success');
        Notification::assertSentTo($user, ResetPassword::class);
        $token = Password::broker()->createToken($user);
        $this->post('/reset-password', ['email' => $user->email, 'token' => $token, 'password' => 'NewPassword1234', 'password_confirmation' => 'NewPassword1234'])->assertRedirect('/login');
        $this->assertTrue(Hash::check('NewPassword1234', $user->fresh()->password));
    }

    public function test_profile_cannot_change_role_or_placement(): void
    {
        $user = $this->user();
        $block = $user->student->block_id;
        $this->actingAs($user)->put('/profile', ['name' => 'Changed Name', 'role' => 'admin', 'block_id' => 2, 'email' => 'changed@example.com'])->assertRedirect();
        $this->assertSame('student', $user->fresh()->role);
        $this->assertSame($block, $user->student->fresh()->block_id);
        $this->assertSame('student@example.com', $user->fresh()->email);
        $this->put('/profile/password', ['current_password' => 'CampusDemo!2026', 'password' => 'ChangedPassword123', 'password_confirmation' => 'ChangedPassword123'])->assertSessionHasNoErrors();
    }

    public function test_admin_catalog_pages_and_forms_render(): void
    {
        $this->actingAs($this->admin());
        foreach (Catalog::all() as $resource => [$model]) {
            $this->get('/admin/manage/'.$resource)->assertOk();
            $this->get('/admin/manage/'.$resource.'/create')->assertOk();
            $row = $resource === 'users' ? $this->teacher() : $model::first();
            $this->get('/admin/manage/'.$resource.'/'.$row->id.'/edit')->assertOk();
        }
        $this->get('/admin/settings')->assertOk();
    }

    public function test_admin_crud_program_subject_and_block(): void
    {
        $this->actingAs($this->admin());
        $this->post('/admin/manage/programs', ['code' => 'BSCS', 'name' => 'Computer Science'])->assertSessionHasNoErrors();
        $program = Program::where('code', 'BSCS')->firstOrFail();
        $this->put('/admin/manage/programs/'.$program->id, ['code' => 'BSCS', 'name' => 'BS Computer Science'])->assertSessionHasNoErrors();
        $this->post('/admin/manage/blocks', ['academic_year_id' => 1, 'program_id' => $program->id, 'year_level_id' => 1, 'name' => '3A', 'semester' => 1])->assertSessionHasNoErrors();
        $block = Block::where('name', '3A')->firstOrFail();
        $this->delete('/admin/manage/blocks/'.$block->id)->assertSessionHasNoErrors();
        $this->delete('/admin/manage/programs/'.$program->id)->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('programs', ['id' => $program->id]);
        $this->post('/admin/manage/subjects', ['code' => 'TEST101', 'name' => 'Test Subject', 'units' => 3, 'status' => 'active'])->assertSessionHasNoErrors();
        $subject = Subject::where('code', 'TEST101')->firstOrFail();
        $this->delete('/admin/manage/subjects/'.$subject->id)->assertSessionHasNoErrors();
    }

    public function test_duplicate_email_and_role_escalation_are_rejected(): void
    {
        $this->actingAs($this->admin())->post('/admin/manage/users', ['name' => 'Duplicate', 'email' => 'student@example.com', 'role' => 'student', 'status' => 'inactive'])->assertSessionHasErrors('email');
        $this->post('/admin/manage/users', ['name' => 'Bad Admin', 'email' => 'bad@example.com', 'role' => 'admin', 'status' => 'active'])->assertSessionHasErrors('role');
        $this->actingAs($this->teacher())->get('/admin/manage/users')->assertForbidden();
        $this->actingAs($this->user())->post('/admin/manage/programs', ['code' => 'BAD', 'name' => 'Denied'])->assertForbidden();
    }

    public function test_teacher_assignment_auto_enrolls_block_students(): void
    {
        $subject = Subject::create(['code' => 'NEW', 'name' => 'New subject', 'units' => 3, 'status' => 'active']);
        $this->actingAs($this->admin())->post('/admin/manage/teacher-assignments', ['teacher_id' => 1, 'block_id' => 1, 'subject_id' => $subject->id])->assertSessionHasNoErrors();
        $class = TeacherAssignment::where('subject_id', $subject->id)->firstOrFail();
        $this->assertEquals(5, $class->enrollments()->count());
        $this->post('/admin/manage/enrollments', ['student_id' => 8, 'teacher_assignment_id' => $class->id])->assertSessionHasErrors('student_id');
    }

    public function test_schedule_conflicts_are_rejected(): void
    {
        $this->actingAs($this->admin())->post('/admin/manage/schedules', ['teacher_assignment_id' => 1, 'day' => 1, 'start_time' => '08:30', 'end_time' => '10:00', 'room' => 'Other'])->assertSessionHasErrors('start_time');
        $this->post('/admin/manage/schedules', ['teacher_assignment_id' => 1, 'day' => 5, 'start_time' => '14:00', 'end_time' => '15:00', 'room' => 'New room'])->assertSessionHasNoErrors();
    }

    public function test_all_role_dashboards_and_common_pages_render(): void
    {
        foreach ([$this->admin(), $this->teacher(), $this->user()] as $user) {
            $this->actingAs($user);
            foreach (['/dashboard', '/classes', '/schedule', '/profile', '/announcements', '/notifications', '/search?q=Week', '/classes/1', '/classes/1/gradebook', '/assignments/1', '/quizzes/1', '/reports/attendance', '/reports/grades', '/reports/students', '/reports/enrollments'] as $url) {
                $this->get($url)->assertOk();
            }
        }
    }

    public function test_class_access_is_scoped_and_invalid_ids_are_safe(): void
    {
        $this->actingAs($this->teacher())->get('/classes/2')->assertForbidden();
        $this->get('/classes/999999')->assertNotFound();
        $this->actingAs($this->user())->get('/classes/7')->assertForbidden();
        $this->get('/classes/1/content/lessons/create')->assertForbidden();
    }

    public function test_unknown_role_cannot_inherit_class_or_announcement_access(): void
    {
        $user = $this->user();
        $user->update(['role' => 'observer']);

        $this->actingAs($user)->get('/classes')->assertForbidden();
        $this->get('/announcements')->assertForbidden();
        $this->get('/schedule')->assertForbidden();
    }

    public function test_class_search_rejects_oversized_input(): void
    {
        $this->actingAs($this->teacher())
            ->get('/classes/1?q='.str_repeat('x', 101))
            ->assertSessionHasErrors('q');
    }

    public function test_mark_all_notifications_as_read_keeps_other_users_unread(): void
    {
        $student = $this->user();
        $other = $this->user('student2@example.com');
        $otherUnreadBefore = $other->unreadNotifications()->count();
        $student->notify(new \App\Notifications\PortalNotice('Class update', 'A new item is ready.'));
        $other->notify(new \App\Notifications\PortalNotice('Class update', 'A new item is ready.'));

        $this->actingAs($student)->post('/notifications/read')->assertSessionHas('success');

        $this->assertSame(0, $student->unreadNotifications()->count());
        $this->assertSame($otherUnreadBefore + 1, $other->unreadNotifications()->count());
    }

    public function test_lessons_crud_and_draft_visibility(): void
    {
        $this->actingAs($this->teacher())->post('/classes/1/content/lessons', ['title' => 'Private draft', 'description' => 'Notes', 'content' => 'Hidden content', 'position' => 2, 'status' => 'draft'])->assertSessionHasNoErrors();
        $lesson = Lesson::where('title', 'Private draft')->firstOrFail();
        $this->actingAs($this->user())->get('/classes/1')->assertDontSee('Private draft');
        $this->actingAs($this->teacher())->put('/classes/1/content/lessons/'.$lesson->id, ['title' => 'Published lesson', 'content' => 'Available now', 'position' => 2, 'status' => 'published'])->assertSessionHasNoErrors();
        $this->actingAs($this->user())->get('/classes/1')->assertSee('Published lesson');
        $this->actingAs($this->teacher())->delete('/classes/1/content/lessons/'.$lesson->id)->assertSessionHasNoErrors();
    }

    public function test_uploads_downloads_invalid_types_and_size(): void
    {
        Storage::fake('local');
        $this->actingAs($this->teacher())->post('/classes/1/content/materials', ['title' => 'Reading', 'status' => 'published', 'attachment' => UploadedFile::fake()->create('reading.pdf', 100, 'application/pdf')])->assertSessionHasNoErrors();
        $material = LearningMaterial::where('title', 'Reading')->firstOrFail();
        $this->actingAs($this->user())->get('/files/materials/'.$material->id)->assertOk();
        $this->actingAs($this->user('student8@example.com'))->get('/files/materials/'.$material->id)->assertForbidden();
        $this->actingAs($this->teacher())->post('/classes/1/content/materials', ['title' => 'Bad', 'status' => 'draft', 'attachment' => UploadedFile::fake()->create('bad.php', 5, 'application/x-php')])->assertSessionHasErrors('attachment');
        $this->post('/classes/1/content/materials', ['title' => 'Large', 'status' => 'draft', 'attachment' => UploadedFile::fake()->create('large.pdf', 21000, 'application/pdf')])->assertSessionHasErrors('attachment');
    }

    public function test_late_submission_versions_and_grading(): void
    {
        $assignment = Assignment::first();
        $assignment->update(['due_at' => now()->subHour()]);
        $this->actingAs($this->user())->post('/assignments/1/submit', ['answer' => 'My new response'])->assertSessionHasNoErrors();
        $submission = $assignment->submissions()->latest('id')->first();
        $this->assertTrue($submission->is_late);
        $this->assertEquals(2, $submission->version);
        $this->assertEquals(2, $assignment->submissions()->count());
        $this->actingAs($this->teacher())->put('/submissions/'.$submission->id.'/grade', ['score' => 101, 'status' => 'graded'])->assertSessionHasErrors('score');
        $this->put('/submissions/'.$submission->id.'/grade', ['score' => 85, 'feedback' => 'Good work', 'status' => 'returned'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('grades', ['source_type' => 'assignment', 'source_id' => 1, 'student_id' => 1, 'score' => 85]);
        $this->actingAs($this->user())->get('/assignments/1')->assertSee('Good work');
    }

    public function test_submission_and_grading_idor_denied(): void
    {
        $this->actingAs($this->user('student2@example.com'))->get('/files/submissions/1')->assertForbidden();
        $this->actingAs($this->user())->put('/submissions/1/grade', ['score' => 100, 'status' => 'graded'])->assertForbidden();
        $this->actingAs($this->user('teacher2@example.com'))->put('/submissions/1/grade', ['score' => 100, 'status' => 'graded'])->assertForbidden();
        $this->actingAs($this->user())->post('/assignments/7/submit', ['answer' => 'Intrusion'])->assertForbidden();
    }

    public function test_quiz_scoring_duplicate_attempt_and_answer_secrecy(): void
    {
        $quiz = Quiz::first();
        $this->actingAs($this->user())->post('/quizzes/1/start')->assertRedirect();
        $attempt = QuizAttempt::first();
        $this->get('/attempts/'.$attempt->id)->assertOk()->assertDontSee('Correct answer:')->assertDontSee('correct_answer');
        $answers = $quiz->questions->pluck('correct_answer', 'id')->all();
        $this->post('/attempts/'.$attempt->id, ['answers' => $answers])->assertRedirect();
        $this->assertEquals(15, $attempt->fresh()->score);
        $this->assertEquals(3, $attempt->answers()->count());
        $this->post('/attempts/'.$attempt->id, ['answers' => []])->assertRedirect();
        $this->assertEquals(15, $attempt->fresh()->score);
        $this->post('/quizzes/1/start')->assertSessionHasErrors('quiz');
        $this->actingAs($this->user('student2@example.com'))->get('/attempts/'.$attempt->id)->assertForbidden();
    }

    public function test_quiz_time_limit_is_enforced_server_side(): void
    {
        $this->actingAs($this->user())->post('/quizzes/1/start');
        $attempt = QuizAttempt::first();
        $this->travel(16)->minutes();
        $this->post('/attempts/'.$attempt->id, ['answers' => Quiz::first()->questions->pluck('correct_answer', 'id')->all()])->assertRedirect();
        $this->assertEquals(0, $attempt->fresh()->score);
        $this->assertNotNull($attempt->fresh()->completed_at);
    }

    public function test_unavailable_and_empty_quizzes_are_rejected(): void
    {
        Quiz::first()->update(['status' => 'draft']);
        $this->actingAs($this->user())->get('/quizzes/1')->assertNotFound();
        $this->post('/quizzes/1/start')->assertForbidden();
        Quiz::first()->update(['status' => 'published', 'available_from' => now()->addDay()]);
        $this->post('/quizzes/1/start')->assertForbidden();
        $this->actingAs($this->teacher())->post('/classes/1/content/quizzes', ['title' => 'Empty', 'time_limit' => 10, 'max_attempts' => 1, 'available_from' => now()->toDateTimeString(), 'available_until' => now()->addDay()->toDateTimeString(), 'status' => 'published'])->assertSessionHasErrors('status');
    }

    public function test_teacher_can_create_questions_publish_and_lock_quiz(): void
    {
        $data = ['title' => 'New quiz', 'time_limit' => 10, 'max_attempts' => 2, 'available_from' => now()->subMinute()->toDateTimeString(), 'available_until' => now()->addDay()->toDateTimeString(), 'status' => 'draft'];
        $this->actingAs($this->teacher())->post('/classes/1/content/quizzes', $data)->assertSessionHasNoErrors();
        $quiz = Quiz::latest('id')->first();
        $q = ['question' => 'Choose A', 'option_a' => 'A', 'option_b' => 'B', 'option_c' => 'C', 'option_d' => 'D', 'correct_answer' => 'a', 'points' => 2];
        $this->post('/quizzes/'.$quiz->id.'/questions', $q)->assertSessionHasNoErrors();
        $data['status'] = 'published';
        $this->put('/classes/1/content/quizzes/'.$quiz->id, $data)->assertSessionHasNoErrors();
        $this->actingAs($this->user())->post('/quizzes/'.$quiz->id.'/start')->assertRedirect();
        $this->actingAs($this->teacher())->put('/classes/1/content/quizzes/'.$quiz->id, $data)->assertSessionHasErrors('quiz');
    }

    public function test_attendance_record_duplicate_threshold_and_audit(): void
    {
        $session = $this->attendance();
        $url = '/attendance/sessions/'.$session->id.'/records';
        $this->actingAs($this->teacher())->get('/attendance/sessions/'.$session->id)->assertOk();
        $this->post($url, ['student_id' => 1, 'status' => 'present', 'minutes_late' => 14])->assertSessionHasNoErrors();
        $record = $session->records()->first();
        $this->assertSame('present', $record->status);
        $this->post($url, ['student_id' => 1, 'status' => 'present'])->assertSessionHasErrors('student_id');
        $this->put($url.'/1', ['status' => 'present', 'minutes_late' => 15, 'reason' => 'Arrival corrected'])->assertSessionHasNoErrors();
        $this->assertSame('late', $record->fresh()->status);
        $this->assertEquals(2, $record->logs()->count());
    }

    public function test_future_attendance_old_edits_and_admin_reason(): void
    {
        $this->actingAs($this->teacher())->post('/classes/1/attendance', ['date' => today()->addDay()->toDateString(), 'start_time' => '12:00', 'end_time' => '13:00'])->assertSessionHasErrors('date');
        $session = $this->attendance(null, 8);
        $record = AttendanceRecord::create(['attendance_session_id' => $session->id, 'student_id' => 1, 'status' => 'absent']);
        $url = '/attendance/sessions/'.$session->id.'/records/1';
        $this->put($url, ['status' => 'present'])->assertSessionHasErrors('date');
        $this->actingAs($this->admin())->put($url, ['status' => 'present'])->assertSessionHasErrors('reason');
        $this->put($url, ['status' => 'present', 'reason' => 'Verified correction request'])->assertSessionHasNoErrors();
        $this->assertSame('present', $record->fresh()->status);
    }

    public function test_excuse_workflow_and_student_attendance_protection(): void
    {
        $session = $this->attendance();
        $record = AttendanceRecord::create(['attendance_session_id' => $session->id, 'student_id' => 1, 'status' => 'absent']);
        $this->actingAs($this->user())->post('/attendance/'.$record->id.'/excuse', ['reason' => 'I had a documented appointment.'])->assertSessionHasNoErrors();
        $this->put('/attendance/sessions/'.$session->id.'/records/1', ['status' => 'excused'])->assertForbidden();
        $this->actingAs($this->teacher())->put('/excuses/'.$record->fresh()->excuse->id, ['status' => 'approved', 'review_note' => 'Documentation reviewed.'])->assertSessionHasNoErrors();
        $this->assertSame('excused', $record->fresh()->status);
    }

    public function test_attendance_rate_excludes_excused_and_empty_is_na(): void
    {
        $this->assertNull(AttendanceService::rate(collect()));
        $this->assertNull(AttendanceService::rate(collect([['status' => 'excused']])));
        $this->assertEquals(66.7, AttendanceService::rate(collect([['status' => 'present'], ['status' => 'late'], ['status' => 'absent'], ['status' => 'excused']])));
    }

    public function test_reports_export_valid_pdf_and_excel_and_scope_students(): void
    {
        $this->actingAs($this->user());
        $this->get('/reports/attendance?student_id=2')->assertOk()->assertDontSee('Demo Casey Reyes');
        $pdf = $this->get('/reports/attendance?format=pdf');
        $pdf->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
        $xlsx = $this->get('/reports/grades?format=xlsx');
        $xlsx->assertOk();
        $path = $xlsx->baseResponse->getFile()->getPathname();
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($path));
        $this->assertNotFalse($zip->locateName('xl/worksheets/sheet1.xml'));
        $zip->close();
    }

    public function test_announcements_are_scoped_and_teacher_cannot_broadcast(): void
    {
        $this->actingAs($this->teacher())->post('/announcements', ['title' => 'Class note', 'body' => 'For our class', 'teacher_assignment_id' => 1])->assertSessionHasNoErrors();
        $this->post('/announcements', ['title' => 'Intrusion', 'body' => 'Wrong class', 'teacher_assignment_id' => 2])->assertForbidden();
        $this->actingAs($this->user('student8@example.com'))->get('/announcements')->assertDontSee('Class note');
        $this->actingAs($this->user())->get('/announcements')->assertSee('Class note');
    }

    public function test_reminders_do_not_duplicate(): void
    {
        Assignment::first()->update(['due_at' => now()->addHours(12)]);
        $this->artisan('lms:deadlines')->assertSuccessful();
        $count = DB::table('notifications')->count();
        $this->artisan('lms:deadlines')->assertSuccessful();
        $this->assertEquals($count, DB::table('notifications')->count());
    }

    public function test_content_forms_and_empty_class_render(): void
    {
        $this->actingAs($this->teacher());
        foreach (['lessons', 'materials', 'assignments', 'quizzes'] as $kind) {
            $this->get('/classes/1/content/'.$kind.'/create')->assertOk();
            $this->get('/classes/1/content/'.$kind.'/1/edit')->assertOk();
        }
        $subject = Subject::create(['code' => 'EMPTY', 'name' => 'Empty class', 'units' => 3, 'status' => 'active']);
        $class = TeacherAssignment::create(['teacher_id' => 1, 'block_id' => 1, 'subject_id' => $subject->id]);
        $this->get('/classes/'.$class->id)->assertOk()->assertSee('No lessons available yet.');
        $this->get('/classes/'.$class->id.'/gradebook')->assertOk()->assertSee('No enrolled students.');
    }

    public function test_deleted_account_cannot_login_and_admin_is_protected(): void
    {
        $user = $this->user();
        $user->delete();
        $this->post('/login', ['email' => $user->email, 'password' => 'CampusDemo!2026'])->assertSessionHasErrors('email');
        $this->actingAs($this->admin())->delete('/admin/manage/users/'.$this->admin()->id)->assertForbidden();
    }

    public function test_seven_day_boundary_and_out_of_class_attendance(): void
    {
        $session = $this->attendance(null, 7);
        $this->actingAs($this->teacher())->post('/attendance/sessions/'.$session->id.'/records', ['student_id' => 1, 'status' => 'present'])->assertSessionHasNoErrors();
        $this->post('/attendance/sessions/'.$session->id.'/records', ['student_id' => 8, 'status' => 'present'])->assertForbidden();
        $this->actingAs($this->user('teacher2@example.com'))->get('/attendance/sessions/'.$session->id)->assertForbidden();
    }

    public function test_configurable_late_threshold_and_duplicate_session(): void
    {
        $this->actingAs($this->admin())->put('/admin/settings', ['late_threshold' => 20])->assertSessionHasNoErrors();
        $data = ['date' => today()->toDateString(), 'start_time' => '16:00', 'end_time' => '17:00'];
        $this->actingAs($this->teacher())->post('/classes/1/attendance', $data)->assertRedirect();
        $session = AttendanceSession::latest('id')->first();
        $this->assertEquals(20, $session->late_threshold);
        $this->post('/classes/1/attendance', $data)->assertSessionHasErrors('date');
    }

    public function test_multiple_quiz_attempts_keep_best_grade(): void
    {
        $quiz = Quiz::first();
        $quiz->update(['max_attempts' => 2]);
        $this->actingAs($this->user())->post('/quizzes/1/start');
        $attempt = QuizAttempt::latest('id')->first();
        $this->post('/attempts/'.$attempt->id, ['answers' => $quiz->questions->pluck('correct_answer', 'id')->all()]);
        $this->post('/quizzes/1/start');
        $second = QuizAttempt::latest('id')->first();
        $this->assertEquals(2, $second->attempt_number);
        $this->post('/attempts/'.$second->id, ['answers' => []]);
        $this->assertDatabaseHas('grades', ['source_type' => 'quiz', 'source_id' => 1, 'student_id' => 1, 'score' => 15]);
        $this->post('/quizzes/1/start')->assertSessionHasErrors('quiz');
    }

    public function test_unpublished_material_and_cross_class_content_ids_are_denied(): void
    {
        LearningMaterial::first()->update(['status' => 'draft']);
        $this->actingAs($this->user())->get('/files/materials/1')->assertNotFound();
        $this->actingAs($this->teacher())->get('/classes/1/content/lessons/2/edit')->assertNotFound();
        Quiz::first()->update(['status' => 'draft']);
        $this->delete('/quizzes/1/questions/4')->assertNotFound();
    }

    public function test_all_export_types_and_report_pagination(): void
    {
        $this->actingAs($this->admin());
        foreach (['attendance', 'grades', 'students', 'enrollments'] as $kind) {
            $this->get('/reports/'.$kind.'?format=pdf')->assertOk()->assertHeader('content-type', 'application/pdf');
            $this->get('/reports/'.$kind.'?format=xlsx')->assertOk();
        }
        $this->get('/reports/enrollments?page=2')->assertOk();
        $this->get('/reports/attendance?academic_year_id=1&semester=1&program_id=1&year_level_id=1&block_id=1&subject_id=1&teacher_id=1&status=late')->assertOk();
        $this->get('/reports/attendance?from=2026-10-01&to=2026-09-01')->assertSessionHasErrors('to');
    }

    public function test_csrf_is_required_outside_testing_environment(): void
    {
        $this->app['env'] = 'local';
        $this->post('/login',['email' => 'student@example.com', 'password' => 'CampusDemo!2026'])->assertStatus(419);
        $this->assertGuest();
    }
}
