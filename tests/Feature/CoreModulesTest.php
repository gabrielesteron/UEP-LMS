<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\AttendanceSession;
use App\Models\LearningMaterial;
use App\Models\User;
use App\Models\YearLevel;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CoreModulesTest extends TestCase
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

    private function dom(string $html): DOMXPath
    {
        $document = new DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$html);

        return new DOMXPath($document);
    }

    public function test_every_role_has_three_modules_and_every_navigation_link_loads(): void
    {
        foreach (['admin', 'teacher', 'student'] as $role) {
            $this->actingAs($this->user($role));
            $page = $this->get('/dashboard')->assertOk();
            $dom = $this->dom($page->getContent());
            $modules = $dom->query('//aside/nav[@data-core-module]');
            $this->assertSame(['Academic Management', 'Learning & Activities', 'Student Monitoring'], array_map(fn ($node) => $node->getAttribute('aria-label'), iterator_to_array($modules)));
            $urls = array_unique(array_map(fn ($node) => html_entity_decode($node->getAttribute('href')), iterator_to_array($dom->query('//aside//a'))));
            foreach (['/search', '/notifications', '/admin/settings', '/reports/students', '/reports/enrollments'] as $hidden) {
                $this->assertNotContains($hidden, $urls);
            }
            foreach ($urls as $url) {
                if ($role !== 'admin') {
                    $this->assertStringNotContainsString('/admin/', $url);
                }
                $this->get($url)->assertOk()->assertDontSee('@include(')->assertDontSee('@if(')->assertDontSee('@csrf');
            }
            $this->assertContains('/announcements', $urls);
            $this->assertContains('/profile', $urls);
        }
    }

    public function test_year_level_whole_number_create_edit_and_validation(): void
    {
        $this->actingAs($this->user('admin'));
        $page = $this->get('/admin/manage/year-levels/create')->assertOk();
        $dom = $this->dom($page->getContent());
        $level = $dom->query('//input[@name="level"]')->item(0);
        $this->assertSame('1', $level->getAttribute('min'));
        $this->assertSame('1', $level->getAttribute('step'));
        $page->assertSee('Year Level Name')->assertSee('Programs are managed separately');
        $this->assertSame(0, $dom->query('//select[@name="program_id"]')->length);
        $this->post('/admin/manage/year-levels', ['name' => 'Fifth Year', 'level' => 5])->assertRedirect('/admin/manage/year-levels')->assertSessionHasNoErrors();
        $year = YearLevel::where('level', 5)->firstOrFail();
        $this->put('/admin/manage/year-levels/'.$year->id, ['name' => 'Sixth Year', 'level' => 6])->assertRedirect('/admin/manage/year-levels')->assertSessionHasNoErrors();
        $this->post('/admin/manage/year-levels', ['name' => 'Fractional', 'level' => 5.01])->assertSessionHasErrors('level');
        $this->post('/admin/manage/year-levels', ['name' => '', 'level' => 5])->assertSessionHasErrors('name');
        $this->post('/admin/manage/year-levels', ['name' => 'Duplicate', 'level' => 6])->assertSessionHasErrors('level');
        $this->delete('/admin/manage/year-levels/'.$year->id)->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('year_levels', ['id' => $year->id]);
        $dom = $this->dom($this->get('/admin/manage/blocks/create')->assertOk()->getContent());
        $this->assertSame('Program', trim($dom->query('//label[@for="field_program_id"]')->item(0)->textContent));
        $this->assertSame('Year Level', trim($dom->query('//label[@for="field_year_level_id"]')->item(0)->textContent));
    }

    public function test_teacher_controls_and_admin_monitoring_do_not_expose_student_actions(): void
    {
        foreach (['admin', 'teacher', 'student'] as $role) {
            $this->actingAs($this->user($role));
            $page = $this->get('/classes/1')->assertOk()->assertDontSee('href="#quizzes"', false)->assertDontSee('Export roster');
            $dom = $this->dom($page->getContent());
            $this->assertSame($role === 'teacher' ? 3 : 0, $dom->query('//main//a[contains(@href,"/content/") and contains(@href,"/create")]')->length);
            $this->assertSame($role === 'teacher' ? 1 : 0, $dom->query('//form[@action="/classes/1/attendance"]')->length);
            $assignment = $this->dom($this->get('/assignments/1')->assertOk()->getContent());
            $this->assertSame($role === 'teacher' ? 1 : 0, $assignment->query('//form[contains(@action,"/grade")]')->length);
            $this->assertSame($role === 'student' ? 1 : 0, $assignment->query('//form[contains(@action,"/submit")]')->length);
        }
        $this->actingAs($this->user('student'))->get('/reports/attendance')->assertDontSee('Request excuse')->assertDontSee('Export PDF');
        $this->get('/classes/1/content/lessons/create')->assertForbidden();
        $this->actingAs($this->user('teacher'))->get('/classes/2')->assertForbidden();
    }

    public function test_latest_submission_is_visible_history_is_preserved_and_returned_status_is_selected(): void
    {
        $assignment = Assignment::first();
        $this->actingAs($this->user('student'))->post('/assignments/1/submit', ['answer' => 'Latest learner answer'])->assertSessionHasNoErrors();
        $this->get('/assignments/1')->assertOk()->assertSee('Latest learner answer')->assertDontSee('Submission history')->assertDontSee('Version 2');
        $this->assertSame(2, $assignment->submissions()->count());
        $submission = $assignment->submissions()->latest('id')->firstOrFail();
        $this->actingAs($this->user('teacher'))->put('/submissions/'.$submission->id.'/grade', ['score' => 75, 'feedback' => 'Please revise', 'status' => 'returned'])->assertSessionHasNoErrors();
        $dom = $this->dom($this->get('/assignments/1')->assertOk()->getContent());
        $this->assertSame('returned', $dom->query('//select[@name="status"]/option[@selected]')->item(0)->getAttribute('value'));
        $this->from('/assignments/1')->put('/submissions/'.$submission->id.'/grade', ['submission_id' => $submission->id, 'score' => 101, 'feedback' => 'Keep my feedback', 'status' => 'returned'])->assertSessionHasErrors('score');
        $this->get('/assignments/1')->assertSee('Keep my feedback');
        $this->assertDatabaseHas('grades', ['student_id' => 1, 'source_type' => 'assignment', 'source_id' => 1, 'score' => 75]);
    }

    public function test_old_attendance_is_read_only_and_audit_and_excuse_ui_are_hidden(): void
    {
        $session = AttendanceSession::create(['teacher_assignment_id' => 1, 'date' => today()->subDays(8), 'start_time' => '15:00', 'end_time' => '16:00', 'late_threshold' => 15]);
        $this->actingAs($this->user('teacher'));
        $page = $this->get('/attendance/sessions/'.$session->id)->assertOk()->assertSee('This session is read only')->assertDontSee('Audit history')->assertDontSee('Save review');
        $this->assertSame(5, $this->dom($page->getContent())->query('//fieldset[@disabled]')->length);
        $this->post('/attendance/sessions/'.$session->id.'/records', ['student_id' => 1, 'status' => 'present'])->assertSessionHasErrors('date');
    }

    public function test_missing_material_attachment_is_not_a_broken_download_link(): void
    {
        $material = LearningMaterial::first();
        $material->update(['path' => '']);
        $this->actingAs($this->user('student'))->get('/classes/'.$material->teacher_assignment_id)->assertOk()->assertSee('No file attached')->assertDontSee('href="/files/materials/'.$material->id.'"', false);
    }

    public function test_catalog_relationship_choices_do_not_query_once_per_table_cell(): void
    {
        $this->actingAs($this->user('admin'));
        DB::enableQueryLog();
        $this->get('/admin/manage/students')->assertOk()->assertSee('BSIT')->assertSee('Block');
        $queries = collect(DB::getQueryLog())->filter(fn ($entry) => str_contains($entry['query'], 'from "blocks"'));
        $this->assertSame(1, $queries->count());
        DB::disableQueryLog();
    }

    public function test_advanced_features_can_be_restored_without_changing_routes_or_data(): void
    {
        config(['lms.show_advanced_features' => true]);
        $this->actingAs($this->user('teacher'))->get('/classes/1')->assertOk()->assertSee('href="#quizzes"', false)->assertSee('Export roster');
        $this->get('/reports/attendance')->assertOk()->assertSee('Export PDF');
        $this->actingAs($this->user('student'))->get('/assignments/1')->assertOk()->assertSee('Version 1');
        $this->get('/quizzes/1')->assertOk();
    }

    public function test_teacher_announcement_without_a_class_has_validation_instead_of_a_missing_page(): void
    {
        $this->actingAs($this->user('teacher'))->from('/announcements')->post('/announcements', ['title' => 'Class notice', 'body' => 'Please prepare for the next lesson'])->assertRedirect('/announcements')->assertSessionHasErrors('teacher_assignment_id');
        $this->get('/announcements')->assertOk();
    }

    public function test_forms_with_no_required_relationship_options_disable_the_save_action(): void
    {
        User::where('role', 'teacher')->delete();
        $this->actingAs($this->user('admin'));
        $page = $this->get('/admin/manage/teachers/create')->assertOk()->assertSee('Create the required related records');
        $this->assertSame(1, $this->dom($page->getContent())->query('//button[@disabled]')->length);
    }

    public function test_attendance_report_accepts_a_single_end_date_filter(): void
    {
        $this->actingAs($this->user('student'))->get('/reports/attendance?to='.today()->format('Y-m-d'))->assertOk();
    }

    public function test_awaiting_grading_counts_latest_submissions_instead_of_hidden_versions(): void
    {
        $this->actingAs($this->user('student'));
        $this->post('/assignments/1/submit', ['answer' => 'First replacement'])->assertSessionHasNoErrors();
        $this->post('/assignments/1/submit', ['answer' => 'Latest replacement'])->assertSessionHasNoErrors();
        $this->actingAs($this->user('admin'))->get('/dashboard')->assertOk()->assertViewHas('stats', fn ($stats) => $stats['Awaiting grading'] === 1);
        $this->assertSame(3, Assignment::first()->submissions()->count());
    }
}
