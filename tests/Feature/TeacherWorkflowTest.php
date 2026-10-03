<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\AttendanceSession;
use App\Models\Enrollment;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TeacherWorkflowTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function user(string $email = 'teacher@example.com'): User
    {
        return User::where('email', $email)->firstOrFail();
    }

    private function attendanceSession(int $daysAgo = 0): AttendanceSession
    {
        return AttendanceSession::create(['teacher_assignment_id' => 1, 'date' => today()->subDays($daysAgo),
            'start_time' => '14:00', 'end_time' => '15:00', 'late_threshold' => 15]);
    }

    private function attendanceRows(): array
    {
        return collect(range(1, 5))->mapWithKeys(fn ($id) => [$id => ['student_id' => $id, 'status' => 'present', 'minutes_late' => 0, 'remarks' => null]])->all();
    }

    private function submission(int $studentId, int $version = 1): AssignmentSubmission
    {
        return AssignmentSubmission::create(['assignment_id' => 1, 'student_id' => $studentId, 'version' => $version,
            'answer' => 'Student work '.$version, 'submitted_at' => now(), 'is_late' => false, 'status' => 'submitted']);
    }

    public function test_class_workspace_has_role_appropriate_quick_actions_and_sections(): void
    {
        $this->actingAs($this->user())->get('/classes/1')->assertOk()
            ->assertSee('Overview')->assertSee('Learning')->assertSee('Monitoring')
            ->assertSee('+ Add Lesson')->assertSee('+ Add Material')->assertSee('+ Create Assignment')
            ->assertSee('Take Attendance')->assertSee('Enter Grades')->assertSee('Submissions &amp; Grades', false);
        foreach (['lessons', 'materials', 'assignments'] as $kind) {
            $this->get('/classes/1/content/'.$kind.'/create')->assertOk();
        }
        $this->actingAs($this->user('student@example.com'))->get('/classes/1')->assertOk()->assertDontSee('+ Add Lesson')->assertSee('View Grades')->assertSee('My Schedule');
        $this->actingAs($this->user('admin@example.com'))->get('/classes/1')->assertOk()->assertDontSee('+ Add Lesson')->assertDontSee('Take Attendance');
        $this->actingAs($this->user('teacher2@example.com'))->get('/classes/1')->assertForbidden();
    }

    public function test_bulk_attendance_saves_present_with_individual_changes_and_keeps_audits(): void
    {
        $session = $this->attendanceSession();
        $rows = $this->attendanceRows();
        $rows[2]['status'] = 'late';
        $rows[2]['minutes_late'] = 18;
        $rows[3]['status'] = 'absent';
        $rows[3]['minutes_late'] = null;
        $rows[4]['status'] = 'excused';
        $rows[4]['minutes_late'] = null;
        $url = '/attendance/sessions/'.$session->id.'/bulk-records';
        $this->actingAs($this->user())->get('/attendance/sessions/'.$session->id)->assertOk()->assertSee('Mark All Present')->assertSee('Save Attendance');
        $this->put($url, ['records' => $rows])->assertSessionHasNoErrors()->assertSessionHas('success');
        $this->assertSame(5, $session->records()->count());
        foreach ([1 => 'present', 2 => 'late', 3 => 'absent', 4 => 'excused', 5 => 'present'] as $id => $status) {
            $record = $session->records()->where('student_id', $id)->firstOrFail();
            $this->assertSame($status, $record->status);
            $this->assertSame(1, $record->logs()->count());
        }
        $rows[2]['status'] = 'present';
        $rows[2]['minutes_late'] = 0;
        $this->put($url, ['records' => $rows])->assertSessionHasNoErrors();
        $this->assertSame(5, $session->records()->count());
        $this->assertSame(2, $session->records()->where('student_id', 2)->first()->logs()->count());
        $this->assertSame(1, $session->records()->where('student_id', 1)->first()->logs()->count());
    }

    public function test_bulk_attendance_validation_and_foreign_students_do_not_partially_save(): void
    {
        $session = $this->attendanceSession();
        $rows = $this->attendanceRows();
        $rows[5]['status'] = 'unknown';
        $this->actingAs($this->user())->put('/attendance/sessions/'.$session->id.'/bulk-records', ['records' => $rows])->assertSessionHasErrors('records.5.status');
        $this->assertSame(0, $session->records()->count());
        $rows = $this->attendanceRows();
        $rows[5]['student_id'] = 8;
        $this->put('/attendance/sessions/'.$session->id.'/bulk-records', ['records' => $rows])->assertForbidden();
        $this->assertSame(0, $session->records()->count());
        $rows[5]['student_id'] = 1;
        $this->put('/attendance/sessions/'.$session->id.'/bulk-records', ['records' => $rows])->assertSessionHasErrors('records.1.student_id');
        $this->assertSame(0, $session->records()->count());
    }

    public function test_bulk_attendance_preserves_age_threshold_and_admin_reason_rules(): void
    {
        $session = $this->attendanceSession(8);
        $url = '/attendance/sessions/'.$session->id.'/bulk-records';
        $rows = $this->attendanceRows();
        $this->actingAs($this->user())->put($url, ['records' => $rows])->assertSessionHasErrors('date');
        $this->actingAs($this->user('admin@example.com'))->put($url, ['records' => $rows])->assertSessionHasErrors('records.1.reason');
        foreach ($rows as &$row) {
            $row['reason'] = 'Verified administrator correction';
        }
        unset($row);
        $rows[1]['minutes_late'] = 15;
        $this->put($url, ['records' => $rows])->assertSessionHasNoErrors();
        $record = $session->records()->where('student_id', 1)->firstOrFail();
        $this->assertSame('late', $record->status);
        $this->assertSame('Verified administrator correction', $record->logs()->first()->reason);
    }

    public function test_bulk_attendance_permission_checks_and_enrollment_queries_are_scoped(): void
    {
        $session = $this->attendanceSession();
        $url = '/attendance/sessions/'.$session->id.'/bulk-records';
        foreach (['student@example.com', 'teacher2@example.com'] as $email) {
            $this->actingAs($this->user($email))->put($url, ['records' => $this->attendanceRows()])->assertForbidden();
        }
        DB::enableQueryLog();
        $this->actingAs($this->user())->put($url, ['records' => $this->attendanceRows()])->assertSessionHasNoErrors();
        $enrollmentQueries = collect(DB::getQueryLog())->filter(fn ($query) => str_starts_with($query['query'], 'select') && str_contains($query['query'], 'from "enrollments"'));
        $this->assertSame(1, $enrollmentQueries->count());
        DB::disableQueryLog();
    }

    public function test_bulk_grading_saves_latest_work_and_shows_students_without_submissions(): void
    {
        $latest = $this->submission(1, 2);
        $second = $this->submission(2);
        $this->actingAs($this->user())->get('/assignments/1')->assertOk()->assertSee('Save All Grades')->assertSee('No submission yet')->assertDontSee('Version 2');
        $data = ['grades' => [$latest->id => ['score' => 85, 'feedback' => 'Good work', 'status' => 'graded'],
            $second->id => ['score' => 70, 'feedback' => 'Please revise', 'status' => 'returned']]];
        $this->from('/assignments/1')->put('/assignments/1/grades', $data)->assertRedirect('/assignments/1')->assertSessionHasNoErrors();
        $this->assertDatabaseHas('grades', ['source_type' => 'assignment', 'source_id' => 1, 'student_id' => 1, 'score' => 85]);
        $this->assertDatabaseHas('grades', ['source_type' => 'assignment', 'source_id' => 1, 'student_id' => 2, 'score' => 70]);
        $this->assertSame('returned', $second->fresh()->status);
        $this->assertSame(3, Assignment::find(1)->submissions()->count());
        $this->actingAs($this->user('student2@example.com'))->get('/assignments/1')->assertSee('Please revise')->assertSee('70 / 100')->assertDontSee('Save All Grades');
    }

    public function test_existing_submission_remains_visible_and_gradable_after_enrollment_removal(): void
    {
        Enrollment::where('teacher_assignment_id', 1)->where('student_id', 1)->delete();
        $this->actingAs($this->user('admin@example.com'))->get('/assignments/1')->assertOk()
            ->assertSee('Demo Jamie Flores')->assertSee('Former enrollment')->assertSee('92 / 100')->assertDontSee('Save All Grades');
        $this->actingAs($this->user())->get('/assignments/1')->assertOk()
            ->assertSee('Demo Jamie Flores')->assertSee('Former enrollment')->assertSee('name="expected_count" value="1"', false)
            ->assertSee('name="grades[1][score]"', false)->assertSee('Save All Grades');
        $this->put('/assignments/1/grades', ['expected_count' => 1,
            'grades' => [1 => ['score' => 87, 'feedback' => 'Existing work reviewed', 'status' => 'graded']]])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('grades', ['student_id' => 1, 'source_type' => 'assignment', 'source_id' => 1, 'score' => 87]);
        $this->assertDatabaseMissing('enrollments', ['student_id' => 1, 'teacher_assignment_id' => 1]);
    }

    public function test_bulk_grading_invalid_score_rolls_back_entire_batch_and_preserves_input(): void
    {
        $second = $this->submission(2);
        $data = ['grades' => [1 => ['score' => 50, 'feedback' => 'Keep first feedback', 'status' => 'graded'],
            $second->id => ['score' => 101, 'feedback' => 'Keep second feedback', 'status' => 'returned']]];
        $this->actingAs($this->user())->from('/assignments/1')->put('/assignments/1/grades', $data)->assertSessionHasErrors('grades.'.$second->id.'.score');
        $this->assertSame(92.0, (float) AssignmentSubmission::find(1)->score);
        $this->assertNull($second->fresh()->graded_at);
        $this->get('/assignments/1')->assertOk()->assertSee('Keep first feedback')->assertSee('Keep second feedback');
    }

    public function test_bulk_grading_rejects_stale_versions_and_foreign_submissions_atomically(): void
    {
        $latest = $this->submission(1, 2);
        $this->actingAs($this->user())->put('/assignments/1/grades', ['grades' => [1 => ['score' => 80, 'status' => 'graded']]])->assertSessionHasErrors('grades');
        $this->assertSame(92.0, (float) AssignmentSubmission::find(1)->score);
        $other = AssignmentSubmission::where('assignment_id', 7)->firstOrFail();
        $this->put('/assignments/1/grades', ['grades' => [$latest->id => ['score' => 80, 'status' => 'graded'], $other->id => ['score' => 70, 'status' => 'graded']]])->assertForbidden();
        $this->assertNull($latest->fresh()->graded_at);
    }

    public function test_bulk_grading_denies_students_and_unrelated_teachers_and_skips_blank_scores(): void
    {
        $submission = $this->submission(2);
        $data = ['grades' => [$submission->id => ['score' => 75, 'status' => 'graded']]];
        foreach (['student@example.com', 'teacher2@example.com'] as $email) {
            $this->actingAs($this->user($email))->put('/assignments/1/grades', $data)->assertForbidden();
        }
        $data['grades'][$submission->id]['score'] = null;
        $this->actingAs($this->user())->put('/assignments/1/grades', $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNull($submission->fresh()->graded_at);
        $this->assertDatabaseMissing('grades', ['source_type' => 'assignment', 'source_id' => 1, 'student_id' => 2]);
    }

    public function test_assignment_form_keeps_options_and_explicit_draft_publish_flow(): void
    {
        $this->actingAs($this->user())->get('/classes/1/content/assignments/create')->assertOk()
            ->assertSee('More Options')->assertSee('Save Draft')->assertSee('Publish')->assertSee('name="allow_text"', false);
        $data = ['title' => 'New class assignment', 'instructions' => 'Follow the instructions.', 'description' => 'Optional context',
            'due_at' => now()->addDays(2)->toDateTimeString(), 'total_points' => 20, 'allow_text' => 1, 'status' => 'draft'];
        $this->post('/classes/1/content/assignments', $data)->assertSessionHasNoErrors();
        $assignment = Assignment::where('title', $data['title'])->firstOrFail();
        $this->actingAs($this->user('student@example.com'))->get('/assignments/'.$assignment->id)->assertNotFound();
        $data['status'] = 'published';
        $this->actingAs($this->user())->put('/classes/1/content/assignments/'.$assignment->id, $data)->assertSessionHasNoErrors();
        $this->actingAs($this->user('student@example.com'))->get('/assignments/'.$assignment->id)->assertOk()->assertSee('Follow the instructions.');
        $this->assertSame('Optional context', $assignment->fresh()->description);
        $data['total_points'] = 0;
        $this->actingAs($this->user())->put('/classes/1/content/assignments/'.$assignment->id, $data)->assertSessionHasErrors('total_points');
        $this->get('/classes/1/content/lessons/create')->assertViewHas('row', fn ($lesson) => $lesson->position === 2 && $lesson->status === 'draft');
    }

    public function test_bulk_grade_redirects_and_notifies_changed_students_only(): void
    {
        $student = $this->user('student@example.com');
        $before = $student->notifications()->count();
        $data = ['expected_count' => 1, 'grades' => [1 => ['score' => 88, 'feedback' => 'Reviewed in class', 'status' => 'graded']]];
        $this->actingAs($this->user())->from('/assignments/1')->put('/assignments/1/grades', $data)
            ->assertRedirect('/assignments/1')->assertSessionHasNoErrors()->assertSessionHas('success', 'Grades saved for 1 submissions.');
        $this->assertSame($before + 1, $student->notifications()->count());
        $notice = $student->notifications()->get()->first(fn ($notice) => $notice->data['title'] === 'Assignment graded');
        $this->assertSame('Assignment graded', $notice->data['title']);
        $this->assertSame('/assignments/1', $notice->data['url']);
        $this->put('/assignments/1/grades', $data)->assertRedirect('/assignments/1')->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'No grades changed. Enter a score for the submissions you want to grade.');
        $this->assertSame($before + 1, $student->notifications()->count());
        $this->assertSame(88.0, (float) AssignmentSubmission::find(1)->score);
    }

    public function test_truncated_bulk_forms_fail_before_any_database_writes(): void
    {
        $session = $this->attendanceSession();
        $this->actingAs($this->user())->put('/attendance/sessions/'.$session->id.'/bulk-records',
            ['expected_count' => 5, 'records' => [1 => ['student_id' => 1, 'status' => 'present', 'minutes_late' => 0, 'remarks' => null, 'reason' => null]]])->assertSessionHasErrors('records');
        $this->assertSame(0, $session->records()->count());
        $this->put('/assignments/1/grades', ['expected_count' => 2, 'grades' => [1 => ['score' => 50, 'feedback' => null, 'status' => 'graded']]])->assertSessionHasErrors('grades');
        $this->assertSame(92.0, (float) AssignmentSubmission::find(1)->score);
        $this->put('/attendance/sessions/'.$session->id.'/bulk-records', ['expected_count' => 1, 'records' => [1 => ['student_id' => 1, 'status' => 'present']]])->assertSessionHasErrors('records.1.minutes_late');
        $this->assertSame(0, $session->records()->count());
        $this->put('/assignments/1/grades', ['expected_count' => 1, 'grades' => [1 => ['score' => 50, 'status' => 'graded']]])->assertSessionHasErrors('grades.1.feedback');
        $this->assertSame(92.0, (float) AssignmentSubmission::find(1)->score);
    }

    public function test_overdue_submission_flow_preserves_latest_status_and_history(): void
    {
        Assignment::find(1)->update(['due_at' => now()->subHour()]);
        $this->actingAs($this->user('student@example.com'))->get('/assignments/1')->assertOk()->assertSee('Overdue')->assertSee('Choose File')->assertSee('Submit Assignment');
        $this->post('/assignments/1/submit', ['answer' => 'My latest response'])->assertSessionHasNoErrors();
        $this->get('/assignments/1')->assertOk()->assertSee('My latest response')->assertSee('Late')->assertSee('Submitted')->assertDontSee('Version 2');
        $this->assertSame(2, Assignment::find(1)->submissions()->count());
    }
}
