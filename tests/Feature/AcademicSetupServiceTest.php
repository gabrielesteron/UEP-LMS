<?php

namespace Tests\Feature;

use App\Jobs\SendSetupInvitation;
use App\Models\AcademicYear;
use App\Models\Block;
use App\Models\ClassSchedule;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\Program;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\TeacherAssignment;
use App\Models\User;
use App\Models\YearLevel;
use App\Notifications\Invitation;
use App\Services\AcademicSetup;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AcademicSetupServiceTest extends TestCase
{
    use DatabaseMigrations;

    private AcademicYear $year;

    private Program $program;

    private YearLevel $level;

    private Block $block;

    private Subject $subject;

    private Teacher $teacher;

    private Teacher $secondTeacher;

    private Student $student;

    private TeacherAssignment $classroom;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->year = AcademicYear::create(['name' => '2026-2027', 'starts_on' => '2026-06-01', 'ends_on' => '2027-04-30']);
        $this->program = Program::create(['code' => 'BSIT', 'name' => 'Information Technology']);
        $this->level = YearLevel::create(['name' => 'First Year', 'level' => 1]);
        $this->block = Block::create(['academic_year_id' => $this->year->id, 'program_id' => $this->program->id, 'year_level_id' => $this->level->id, 'semester' => 1, 'name' => 'Block A']);
        $this->subject = Subject::create(['code' => 'INTRO', 'name' => 'Existing Introduction', 'units' => 3, 'description' => 'Preserve this shared description', 'status' => 'active']);
        $this->teacher = Teacher::create(['user_id' => $this->user('teacher@example.com', 'teacher')->id, 'employee_number' => 'T-001']);
        $this->secondTeacher = Teacher::create(['user_id' => $this->user('teacher2@example.com', 'teacher')->id, 'employee_number' => 'T-002']);
        $this->student = Student::create(['user_id' => $this->user('student@example.com', 'student')->id, 'student_number' => 'S-001', 'block_id' => $this->block->id]);
        $this->classroom = TeacherAssignment::create(['teacher_id' => $this->teacher->id, 'block_id' => $this->block->id, 'subject_id' => $this->subject->id]);
        Enrollment::create(['student_id' => $this->student->id, 'teacher_assignment_id' => $this->classroom->id]);
        Lesson::create(['teacher_assignment_id' => $this->classroom->id, 'title' => 'Historical lesson', 'content' => 'Retain the prior course content.', 'position' => 1, 'status' => 'published']);
    }

    private function user(string $email, string $role, string $status = 'active'): User
    {
        return tap((new User)->forceFill(['name' => 'Existing Account', 'email' => $email, 'password' => 'OriginalPassword123', 'role' => $role, 'status' => $status, 'email_verified_at' => $status === 'active' ? now() : null]), fn ($user) => $user->save());
    }

    private function draft(array $overrides = []): array
    {
        return array_replace([
            'academic_year_id' => null, 'academic_year_name' => '2027-2028', 'starts_on' => '2027-06-01', 'ends_on' => '2028-04-30',
            'program_id' => $this->program->id, 'year_level_id' => $this->level->id, 'semester' => 1,
            'blocks' => [['name' => 'New A'], ['name' => 'New B']],
            'subjects' => [['id' => null, 'code' => 'intro', 'name' => 'Attempted rename', 'units' => 6], ['id' => null, 'code' => 'NEW101', 'name' => 'New Subject', 'units' => 3]],
            'assignments' => [['subject' => 0, 'teacher_id' => $this->teacher->id, 'blocks' => [0, 1]], ['subject' => 1, 'teacher_id' => $this->secondTeacher->id, 'blocks' => [0, 1]]],
            'students' => [], 'csv_rows' => [], 'copy_schedules' => false,
        ], $overrides);
    }

    private function counts(): array
    {
        return collect(['academic_years', 'blocks', 'subjects', 'teacher_assignments', 'students', 'users', 'enrollments', 'class_schedules', 'jobs', 'lessons'])
            ->mapWithKeys(fn ($table) => [$table => DB::table($table)->count()])->all();
    }

    private function assertRejectedWithoutChanges(array $draft, string $errorKey): void
    {
        $before = $this->counts();
        try {
            AcademicSetup::create($draft);
            $this->fail('The conflicting setup should have been rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($errorKey, $exception->errors());
        }
        $this->assertSame($before, $this->counts());
        Notification::assertNothingSent();
    }

    public function test_multiple_blocks_reuse_subjects_assign_teachers_and_preserve_old_student_history(): void
    {
        $userBefore = $this->student->user->getAttributes();
        $subjectBefore = $this->subject->fresh()->getAttributes();
        $summary = AcademicSetup::create($this->draft(['students' => [['id' => $this->student->id, 'block' => 1]]]));

        $this->assertSame(2, $summary['blocks']);
        $this->assertSame(2, $summary['subjects']);
        $this->assertSame(4, $summary['classes']);
        $this->assertSame(1, $summary['students']);
        $this->assertSame(2, $summary['enrollments']);
        $this->assertSame(0, $summary['new_users']);
        $targetBlocks = Block::where('academic_year_id', $summary['academic_year_id'])->get()->keyBy('name');
        $this->assertSame($targetBlocks['New B']->id, $this->student->fresh()->block_id);
        $this->assertSame($userBefore, $this->student->user->fresh()->getAttributes());
        $this->assertSame($subjectBefore, $this->subject->fresh()->getAttributes());
        $this->assertDatabaseCount('subjects', 2);
        $this->assertDatabaseCount('teacher_assignments', 5);
        $this->assertDatabaseCount('enrollments', 3);
        $this->assertDatabaseHas('enrollments', ['student_id' => $this->student->id, 'teacher_assignment_id' => $this->classroom->id]);
        foreach ($targetBlocks as $block) {
            $this->assertDatabaseHas('teacher_assignments', ['block_id' => $block->id, 'subject_id' => $this->subject->id, 'teacher_id' => $this->teacher->id]);
        }
        $this->assertDatabaseCount('lessons', 1);
        $this->assertDatabaseCount('jobs', 0);
        Notification::assertNothingSent();
    }

    public function test_optional_students_can_be_skipped_entirely(): void
    {
        $summary = AcademicSetup::create($this->draft());

        $this->assertSame(0, $summary['students']);
        $this->assertSame(0, $summary['enrollments']);
        $this->assertSame(0, $summary['invitations_queued']);
        $this->assertDatabaseCount('students', 1);
        $this->assertDatabaseCount('users', 3);
        $this->assertDatabaseCount('enrollments', 1);
        $this->assertSame($this->block->id, $this->student->fresh()->block_id);
    }

    public function test_csv_creates_inactive_hashed_accounts_profiles_enrollments_and_database_jobs(): void
    {
        $existing = $this->user('unplaced@example.com', 'student', 'inactive');
        $before = $existing->fresh()->getAttributes();
        $summary = AcademicSetup::create($this->draft(['csv_rows' => [
            ['row' => 2, 'student_number' => 'S-002', 'name' => 'New Learner', 'email' => 'new@example.com', 'block' => 'New A'],
            ['row' => 3, 'student_number' => 'S-003', 'name' => 'Replacement Name', 'email' => 'unplaced@example.com', 'block' => 'New B'],
        ]]));

        $this->assertSame(2, $summary['students']);
        $this->assertSame(1, $summary['new_users']);
        $this->assertSame(1, $summary['invitations_queued']);
        $this->assertSame(4, $summary['enrollments']);
        $user = User::where('email', 'new@example.com')->firstOrFail();
        $this->assertSame('New Learner', $user->name);
        $this->assertSame('student', $user->role);
        $this->assertSame('inactive', $user->status);
        $this->assertNull($user->email_verified_at);
        $this->assertNotSame('New Learner', $user->password);
        $this->assertNotSame('unknown', password_get_info($user->password)['algoName']);
        $this->assertFalse(Hash::check('OriginalPassword123', $user->password));
        $this->assertSame($before, $existing->fresh()->getAttributes());
        $this->assertDatabaseHas('students', ['user_id' => $user->id, 'student_number' => 'S-002']);
        $this->assertDatabaseHas('students', ['user_id' => $existing->id, 'student_number' => 'S-003']);
        $this->assertDatabaseCount('jobs', 1);
        $job = DB::table('jobs')->first();
        $payload = json_decode($job->payload, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(SendSetupInvitation::class, $payload['displayName']);
        $this->assertStringContainsString('userId', $payload['data']['command']);
        $this->assertStringNotContainsString($user->password, $job->payload);
        Notification::assertNothingSent();
    }

    private function previousSetup(): array
    {
        $block = Block::create(['academic_year_id' => $this->year->id, 'program_id' => $this->program->id, 'year_level_id' => $this->level->id, 'semester' => 1, 'name' => 'Block B']);
        $secondClass = TeacherAssignment::create(['teacher_id' => $this->teacher->id, 'block_id' => $block->id, 'subject_id' => $this->subject->id]);
        $subject = Subject::create(['code' => 'SECOND', 'name' => 'Second Existing Subject', 'units' => 3, 'status' => 'active']);
        $thirdClass = TeacherAssignment::create(['teacher_id' => $this->secondTeacher->id, 'block_id' => $this->block->id, 'subject_id' => $subject->id]);
        ClassSchedule::create(['teacher_assignment_id' => $this->classroom->id, 'day' => 1, 'start_time' => '08:00', 'end_time' => '09:00', 'room' => 'Lab A']);
        ClassSchedule::create(['teacher_assignment_id' => $secondClass->id, 'day' => 1, 'start_time' => '09:00', 'end_time' => '10:00', 'room' => 'Lab B']);
        ClassSchedule::create(['teacher_assignment_id' => $thirdClass->id, 'day' => 2, 'start_time' => '08:00', 'end_time' => '09:00', 'room' => 'Lab A']);

        return ['academic_year_id' => $this->year->id, 'program_id' => $this->program->id, 'year_level_id' => $this->level->id, 'semester' => 1];
    }

    public function test_duplicate_can_copy_schedules_without_students_or_learning_history(): void
    {
        $scope = $this->previousSetup();
        $copy = AcademicSetup::duplicate($scope, false, true);
        $this->assertCount(2, $copy['blocks']);
        $this->assertCount(2, $copy['subjects']);
        $this->assertCount(2, $copy['assignments']);
        $this->assertSame([], $copy['students']);
        $summary = AcademicSetup::create($this->draft($copy));

        $this->assertSame(3, $summary['classes']);
        $this->assertSame(3, $summary['schedules']);
        $this->assertSame(0, $summary['students']);
        $this->assertDatabaseCount('class_schedules', 6);
        $this->assertDatabaseCount('lessons', 1);
        $this->assertDatabaseCount('students', 1);
        $this->assertDatabaseCount('enrollments', 1);
        $this->assertDatabaseCount('jobs', 0);
        $this->assertSame($this->block->id, $this->student->fresh()->block_id);
        foreach (ClassSchedule::with('classroom.block')->get()->filter(fn ($schedule) => $schedule->classroom->block->academic_year_id === $summary['academic_year_id']) as $schedule) {
            $this->assertContains($schedule->room, ['Lab A', 'Lab B']);
        }
    }

    public function test_duplicate_optionally_moves_students_and_preserves_prior_enrollments(): void
    {
        $scope = $this->previousSetup();
        $copy = AcademicSetup::duplicate($scope, true, false);
        $this->assertSame([['id' => $this->student->id, 'block' => 0]], $copy['students']);
        $summary = AcademicSetup::create($this->draft($copy));

        $this->assertSame(1, $summary['students']);
        $this->assertSame(2, $summary['enrollments']);
        $this->assertSame(0, $summary['schedules']);
        $this->assertDatabaseCount('enrollments', 3);
        $this->assertDatabaseHas('enrollments', ['student_id' => $this->student->id, 'teacher_assignment_id' => $this->classroom->id]);
        $this->assertNotSame($this->block->id, $this->student->fresh()->block_id);
        $this->assertDatabaseCount('class_schedules', 3);
    }

    public function test_duplicate_subject_teacher_pair_is_rejected_without_partial_setup(): void
    {
        $draft = $this->draft();
        $draft['assignments'][] = ['subject' => 0, 'teacher_id' => $this->secondTeacher->id, 'blocks' => [0]];
        $this->assertRejectedWithoutChanges($draft, 'assignments');
    }

    public function test_existing_target_block_and_repeated_confirmation_are_rejected(): void
    {
        $draft = $this->draft(['academic_year_id' => $this->year->id, 'blocks' => [['name' => 'block a']], 'assignments' => [['subject' => 0, 'teacher_id' => $this->teacher->id, 'blocks' => [0]]]]);
        $this->assertRejectedWithoutChanges($draft, 'blocks');
        $valid = $this->draft();
        AcademicSetup::create($valid);
        $this->assertRejectedWithoutChanges($valid, 'academic_year_name');
    }

    public function test_schedule_conflict_rolls_back_new_year_blocks_subjects_and_classes(): void
    {
        $scope = $this->previousSetup();
        // Simulate an inconsistent previous timetable: conflict checks must reject copying it.
        ClassSchedule::where('teacher_assignment_id', $this->classroom->id)->update(['end_time' => '10:00']);
        $copy = AcademicSetup::duplicate($scope, true, true);
        $this->assertRejectedWithoutChanges($this->draft($copy), 'schedules');
        $this->assertSame($this->block->id, $this->student->fresh()->block_id);
        $this->assertDatabaseMissing('academic_years', ['name' => '2027-2028']);
    }

    public function test_csv_and_checkbox_duplicate_student_rejected_before_any_account_or_job_is_created(): void
    {
        $draft = $this->draft(['students' => [['id' => $this->student->id, 'block' => 0]], 'csv_rows' => [['row' => 2, 'student_number' => 'S-001', 'name' => 'Existing Student', 'email' => 'student@example.com', 'block' => 'New A']]]);
        $this->assertRejectedWithoutChanges($draft, 'students');
    }

    public function test_archived_teacher_and_student_accounts_cannot_enter_a_new_setup(): void
    {
        $this->teacher->user->delete();
        $this->assertRejectedWithoutChanges($this->draft(), 'assignments.0.teacher_id');
        $this->teacher->user()->withTrashed()->first()->restore();
        $this->student->user->delete();
        $this->assertRejectedWithoutChanges($this->draft(['students' => [['id' => $this->student->id, 'block' => 0]]]), 'students');
    }

    public function test_duplicate_block_selection_and_out_of_range_references_are_rejected(): void
    {
        $draft = $this->draft();
        $draft['assignments'][0]['blocks'] = [0, 0];
        $this->assertRejectedWithoutChanges($draft, 'assignments');
        $draft = $this->draft();
        $draft['assignments'][0]['blocks'] = [50];
        $this->assertRejectedWithoutChanges($draft, 'assignments.0.blocks');
        $draft = $this->draft();
        $draft['assignments'][0]['subject'] = 50;
        $this->assertRejectedWithoutChanges($draft, 'assignments.0.teacher_id');
        $draft = $this->draft();
        $draft['students'] = [['id' => $this->student->id, 'block' => 50]];
        $this->assertRejectedWithoutChanges($draft, 'students');
    }

    public function test_queue_storage_failure_rolls_back_accounts_profiles_and_academic_setup(): void
    {
        $before = $this->counts();
        $fail = true;
        DB::listen(function ($query) use (&$fail) {
            if ($fail && str_contains(strtolower(str_replace('`', '"', $query->sql)), 'insert into "jobs"')) {
                throw new \RuntimeException('Simulated queue storage failure');
            }
        });
        try {
            AcademicSetup::create($this->draft(['csv_rows' => [['row' => 2, 'student_number' => 'S-FAIL', 'name' => 'Failed Learner', 'email' => 'fail@example.com', 'block' => 'New A']]]));
            $this->fail('The queue storage failure should abort the transaction.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Simulated queue storage failure', $exception->getMessage());
        } finally {
            $fail = false;
        }
        $this->assertSame($before, $this->counts());
        $this->assertDatabaseMissing('users', ['email' => 'fail@example.com']);
        Notification::assertNothingSent();
    }

    public function test_student_selection_validation_uses_bulk_database_lookups(): void
    {
        $many = [['id' => $this->student->id, 'block' => 0]];
        for ($i = 2; $i <= 80; $i++) {
            $user = $this->user('student'.$i.'@example.com', 'student');
            $student = Student::create(['user_id' => $user->id, 'student_number' => 'S-'.$i, 'block_id' => $this->block->id]);
            $many[] = ['id' => $student->id, 'block' => 0];
        }
        DB::enableQueryLog();
        DB::flushQueryLog();
        AcademicSetup::validateDraft($this->draft(['students' => [$many[0]]]));
        $singleQueries = count(DB::getQueryLog());
        DB::flushQueryLog();
        $data = AcademicSetup::validateDraft($this->draft(['students' => $many]));
        $manyQueries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertCount(80, $data['students']);
        $this->assertLessThanOrEqual($singleQueries + 2, $manyQueries);
        $this->assertDatabaseCount('enrollments', 1);
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_imported_account_can_activate_from_its_queued_invitation_and_access_only_enrolled_classes(): void
    {
        $summary = AcademicSetup::create($this->draft(['csv_rows' => [
            ['row' => 2, 'student_number' => 'S-ACTIVATE', 'name' => 'Imported Learner', 'email' => 'activate-import@example.com', 'block' => 'New A'],
        ]]));
        $user = User::where('email', 'activate-import@example.com')->firstOrFail();
        $student = $user->student;
        $this->assertSame('inactive', $user->status);
        $this->assertNull($user->email_verified_at);
        $this->assertNotNull($student);
        $this->assertSame(2, $student->enrollments()->count());
        $this->assertSame($summary['academic_year_id'], $student->block->academic_year_id);
        $this->post('/login', ['email' => $user->email, 'password' => 'OriginalPassword123'])->assertSessionHasErrors('email');
        $this->assertGuest();

        // Execute the actual job queued by setup; the broker and signed URL stay real.
        $payload = json_decode(DB::table('jobs')->first()->payload, true, flags: JSON_THROW_ON_ERROR);
        $job = unserialize($payload['data']['command'], ['allowed_classes' => [SendSetupInvitation::class]]);
        $this->assertInstanceOf(SendSetupInvitation::class, $job);
        $this->assertSame($user->id, $job->userId);
        $job->handle();
        Notification::assertSentTo($user, Invitation::class);
        $invitation = Notification::sent($user, Invitation::class)->first();
        $url = $invitation->toMail($user)->actionUrl;
        $this->get($url)->assertOk()
            ->assertSee('New password')
            ->assertSee('Confirm new password')
            ->assertSee('Verify email & activate', false);
        $password = 'ImportedStudent123';
        $this->post($url, ['password' => $password, 'password_confirmation' => $password])->assertRedirect('/login')->assertSessionHasNoErrors();
        $this->assertGuest();
        $this->assertSame('active', $user->fresh()->status);
        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->assertTrue(Hash::check($password, $user->fresh()->password));
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);

        $this->get('/login')->assertOk();
        $this->post('/login', ['email' => $user->email, 'password' => $password])->assertRedirect('/dashboard')->assertSessionHasNoErrors();
        $this->assertAuthenticatedAs($user);
        $this->get('/dashboard')->assertOk();
        $page = $this->get('/classes')->assertOk()->assertSee('New Subject')->assertSee('Existing Introduction')->assertSee('Block New A');
        $targetClasses = TeacherAssignment::where('block_id', $student->block_id)->get();
        foreach ($targetClasses as $class) {
            $page->assertSee('href="/classes/'.$class->id.'"', false);
            $this->get('/classes/'.$class->id)->assertOk();
        }
        $otherBlock = Block::where('academic_year_id', $summary['academic_year_id'])->where('name', 'New B')->firstOrFail();
        $otherClass = TeacherAssignment::where('block_id', $otherBlock->id)->firstOrFail();
        $this->get('/classes/'.$otherClass->id)->assertForbidden();
        $this->get('/classes/'.$this->classroom->id)->assertForbidden();
        $this->get('/admin/manage/students')->assertForbidden();

        // Retrying a queued invitation must not rotate tokens or resend after activation.
        Notification::fake();
        $passwordHash = $user->fresh()->password;
        $job->handle();
        Notification::assertNothingSent();
        $this->assertSame($passwordHash, $user->fresh()->password);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
    }
}
