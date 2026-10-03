<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSession;
use App\Models\Block;
use App\Models\ClassSchedule;
use App\Models\Grade;
use App\Models\LearningMaterial;
use App\Models\Lesson;
use App\Models\Subject;
use App\Models\TeacherAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DashboardWorkflowTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function user(string $role): User
    {
        return User::where('email', $role.'@example.com')->firstOrFail();
    }

    private function assignment(string $title, array $overrides = []): Assignment
    {
        return Assignment::create(array_merge(['teacher_assignment_id' => 1, 'title' => $title, 'instructions' => 'Submit your work.', 'due_at' => now()->addDay(), 'total_points' => 20, 'allow_text' => true, 'status' => 'published'], $overrides));
    }

    private function submission(Assignment $assignment, string $status, int $version = 1): AssignmentSubmission
    {
        return $assignment->submissions()->create(['student_id' => 1, 'version' => $version, 'answer' => 'My work', 'submitted_at' => now(), 'is_late' => false, 'status' => $status]);
    }

    public function test_admin_has_setup_shortcuts_and_no_attention_section_for_complete_setup(): void
    {
        $this->actingAs($this->user('admin'))->get('/dashboard')->assertOk()
            ->assertSee('+ Set Up School Year')->assertSee('Duplicate Previous Setup')
            ->assertSee('href="/admin/setup"', false)->assertSee('href="/admin/setup?duplicate=1"', false)
            ->assertDontSee('Needs Attention')->assertViewHas('needsAttention', fn ($items) => $items->isEmpty())
            ->assertViewHas('classes', fn ($classes) => $classes->count() === 6)
            ->assertViewHas('stats', fn ($stats) => $stats['Active classes'] === 12);
        $this->actingAs($this->user('teacher'))->get('/dashboard')->assertOk()->assertDontSee('Set Up School Year')->assertDontSee('Duplicate Previous Setup');
    }

    public function test_admin_attention_identifies_actionable_setup_gaps_without_loading_rosters(): void
    {
        $block = Block::first()->replicate();
        $block->name = 'New block';
        $block->save();
        $this->user('teacher')->update(['status' => 'suspended']);
        User::where('email', 'teacher2@example.com')->firstOrFail()->delete();
        User::create(['name' => 'Missing placement', 'email' => 'missing-profile@example.com', 'password' => 'TestPassword123', 'role' => 'student', 'status' => 'active', 'email_verified_at' => now()]);
        ClassSchedule::where('teacher_assignment_id', 1)->delete();
        $page = $this->actingAs($this->user('admin'))->get('/dashboard')->assertOk()->assertSee('Needs Attention')
            ->assertSee('Blocks without subjects')->assertSee('Classes with unavailable teachers')
            ->assertSee('Students missing required information')->assertSee('Classes missing schedules');
        $items = $page->viewData('needsAttention')->keyBy('label');
        $this->assertSame(1, $items['Blocks without subjects']['count']);
        $this->assertSame(8, $items['Classes with unavailable teachers']['count']);
        $this->assertSame(1, $items['Students missing required information']['count']);
        $this->assertSame(1, $items['Classes missing schedules']['count']);
        foreach ($items as $item) {
            $this->get($item['url'])->assertOk();
        }
    }

    public function test_student_attention_uses_latest_submission_and_only_enrolled_published_assignments(): void
    {
        $overdue = $this->assignment('Overdue work', ['due_at' => now()->subDay()]);
        $soon = $this->assignment('Due soon work', ['due_at' => now()->addHours(6)]);
        $returned = $this->assignment('Please revise', ['due_at' => now()->addHours(12)]);
        $this->submission($returned, 'returned');
        $resubmitted = $this->assignment('Already resubmitted');
        $this->submission($resubmitted, 'returned');
        $this->submission($resubmitted, 'submitted', 2);
        $graded = $this->assignment('Already graded');
        $this->submission($graded, 'graded');
        $private = $this->assignment('Other block private assignment', ['teacher_assignment_id' => 7]);
        $draft = $this->assignment('Teacher draft', ['status' => 'draft']);

        $page = $this->actingAs($this->user('student'))->get('/dashboard')->assertOk()
            ->assertSee('Needs Attention')->assertSee('Recent Activity')->assertSee('My Progress')
            ->assertSee('Overdue')->assertSee('Due soon')->assertSee('Needs revision')
            ->assertDontSee('Other block private assignment')->assertDontSee('Teacher draft');
        $ids = $page->viewData('studentAttention')->pluck('id')->all();
        foreach ([$overdue, $soon, $returned] as $assignment) {
            $this->assertContains($assignment->id, $ids);
        }
        foreach ([$resubmitted, $graded, $private, $draft, Assignment::first()] as $assignment) {
            $this->assertNotContains($assignment->id, $ids);
        }
        $this->assertLessThanOrEqual(6, count($ids));
        $this->assertSame('returned', $page->viewData('studentAttention')->firstWhere('id', $returned->id)->latest_submission_status);
    }

    public function test_recent_activity_is_limited_and_never_exposes_drafts_other_classes_or_other_students_grades(): void
    {
        Lesson::create(['teacher_assignment_id' => 1, 'title' => 'Fresh lesson', 'content' => 'Read this', 'position' => 2, 'status' => 'published']);
        Lesson::create(['teacher_assignment_id' => 1, 'title' => 'Secret draft lesson', 'content' => 'Private', 'position' => 3, 'status' => 'draft']);
        Lesson::create(['teacher_assignment_id' => 7, 'title' => 'Secret other block lesson', 'content' => 'Private', 'position' => 2, 'status' => 'published']);
        LearningMaterial::create(['teacher_assignment_id' => 1, 'title' => 'Fresh reading', 'path' => 'test.pdf', 'original_name' => 'test.pdf', 'status' => 'published']);
        $assignment = $this->assignment('Fresh assignment');
        Grade::create(['teacher_assignment_id' => 1, 'student_id' => 1, 'source_type' => 'assignment', 'source_id' => $assignment->id, 'title' => 'My recent grade', 'score' => 18, 'total_points' => 20]);
        Grade::create(['teacher_assignment_id' => 1, 'student_id' => 2, 'source_type' => 'assignment', 'source_id' => $assignment->id, 'title' => 'Secret other student grade', 'score' => 10, 'total_points' => 20]);

        $page = $this->actingAs($this->user('student'))->get('/dashboard')->assertOk()
            ->assertSee('Fresh lesson')->assertSee('Fresh reading')->assertSee('Fresh assignment')
            ->assertSee('My recent grade')->assertSee('18 / 20 points')
            ->assertDontSee('Secret draft lesson')->assertDontSee('Secret other block lesson')->assertDontSee('Secret other student grade');
        $this->assertLessThanOrEqual(8, $page->viewData('recentActivity')->count());
        $this->assertSame(['New assignment', 'New lesson', 'New material', 'Recent grade'], $page->viewData('recentActivity')->pluck('label')->unique()->sort()->values()->all());
        $this->assertEqualsWithDelta(91.7, $page->viewData('gradeAverage'), 0.01);
    }

    public function test_progress_uses_only_student_records_and_preserves_excused_attendance_calculation(): void
    {
        $session = AttendanceSession::create(['teacher_assignment_id' => 1, 'date' => today(), 'start_time' => '14:00', 'end_time' => '15:00', 'late_threshold' => 15]);
        AttendanceRecord::create(['attendance_session_id' => $session->id, 'student_id' => 1, 'status' => 'absent']);
        AttendanceRecord::create(['attendance_session_id' => $session->id, 'student_id' => 2, 'status' => 'present']);
        $this->actingAs($this->user('student'))->get('/dashboard')->assertOk()
            ->assertViewHas('rate', fn ($rate) => $rate === 66.7)
            ->assertViewHas('gradeAverage', fn ($average) => $average === 92.0)
            ->assertSee('Attendance excludes excused absences.');
    }

    public function test_schedule_cards_include_subject_teacher_room_and_only_enrolled_classes(): void
    {
        $privateClass = TeacherAssignment::findOrFail(7);
        $privateClass->subject()->update(['name' => 'Private subject']);
        ClassSchedule::where('teacher_assignment_id', 7)->update(['room' => 'Private room']);
        $this->actingAs($this->user('student'))->get('/schedule')->assertOk()
            ->assertSee('data-weekly-schedule', false)->assertSee('Monday')->assertSee('8:00 AM')
            ->assertSee('PF102')->assertSee('Demo Alex Rivera')->assertSee('IT Lab 1')
            ->assertDontSee('Private room')->assertDontSee('Manage Schedules')
            ->assertViewHas('schedules', fn ($rows) => $rows->count() === 6 && $rows->pluck('teacher_assignment_id')->every(fn ($id) => $id <= 6));
        ClassSchedule::where('teacher_assignment_id', '<=', 6)->delete();
        $this->get('/schedule')->assertOk()->assertSee('No scheduled classes yet.');
    }

    public function test_dashboard_queries_are_limited_and_do_not_load_hidden_advanced_modules(): void
    {
        $this->actingAs($this->user('student'));
        DB::enableQueryLog();
        $page = $this->get('/dashboard')->assertOk();
        $queries = collect(DB::getQueryLog())->pluck('query');
        DB::disableQueryLog();
        $this->assertFalse($queries->contains(fn ($query) => str_contains($query, 'from "quizzes"')));
        $this->assertFalse($queries->contains(fn ($query) => str_contains($query, 'from "notifications"')));
        $this->assertTrue($queries->contains(fn ($query) => str_contains($query, 'from "attendance_records"') && str_contains($query, 'SUM(CASE')));
        $this->assertTrue($queries->contains(fn ($query) => str_contains($query, 'from "lessons"') && str_contains($query, 'limit 2')));
        $this->assertLessThanOrEqual(6, $page->viewData('classes')->count());
        $this->assertTrue($page->viewData('schedules')->isEmpty());
    }

    public function test_class_list_paginates_and_preserves_module_links_and_relationships(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $subject = Subject::create(['code' => 'PAGE'.$i, 'name' => 'Paged subject '.$i, 'units' => 3, 'status' => 'active']);
            TeacherAssignment::create(['teacher_id' => 1, 'block_id' => 1, 'subject_id' => $subject->id]);
        }
        $page = $this->actingAs($this->user('admin'))->get('/classes?module=learning')->assertOk()
            ->assertSee('BSIT')->assertSee('Block 3J')->assertSee('#learning', false);
        $classes = $page->viewData('classes');
        $this->assertSame(22, $classes->total());
        $this->assertSame(18, $classes->count());
        $this->assertStringContainsString('module=learning', $classes->nextPageUrl());
        $this->get('/classes?module=learning&page=2')->assertOk()->assertViewHas('classes', fn ($classes) => $classes->count() === 4);
    }
}
