<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Block;
use App\Models\ClassSchedule;
use App\Models\Enrollment;
use App\Models\Student;
use App\Models\Subject;
use App\Models\TeacherAssignment;
use App\Models\User;
use App\Services\AcademicSetup;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class AcademicSetupWizardTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->actingAs($this->user('admin'));
    }

    private function user(string $role): User
    {
        return User::where('email', $role.'@example.com')->firstOrFail();
    }

    private function draft(): array
    {
        return session()->get('academic_setup');
    }

    private function counts(): array
    {
        return array_map(fn ($model) => $model::count(), [AcademicYear::class, Block::class, Subject::class, TeacherAssignment::class, Student::class, User::class, Enrollment::class, ClassSchedule::class]);
    }

    private function stepData(int $step): array
    {
        return match ($step) {
            1 => ['academic_year_id' => 1, 'semester' => 2, 'program_id' => 1, 'year_level_id' => 1],
            2 => ['blocks' => [['name' => '4A'], ['name' => '4B']]],
            3 => ['subjects' => [['id' => 1, 'code' => 'PF102', 'name' => 'Programming Fundamentals II', 'units' => 3], ['code' => 'HTTP101', 'name' => 'Wizard Test Subject', 'units' => 3]]],
            4 => ['assignments' => [['subject' => 0, 'teacher_id' => 1, 'blocks' => [0, 1]], ['subject' => 1, 'teacher_id' => 2, 'blocks' => [0, 1]]]],
            5 => ['visible_ids' => [1, 6], 'selected' => [1, 6], 'placements' => [1 => 0, 6 => 1], 'action' => 'continue'],
        };
    }

    private function advanceTo(int $completed): array
    {
        $this->get('/admin/setup')->assertOk();
        for ($step = 1; $step <= $completed; $step++) {
            $this->post('/admin/setup/steps/'.$step, ['draft_token' => $this->draft()['token']] + $this->stepData($step))
                ->assertRedirect('/admin/setup?step='.($step + 1))->assertSessionHasNoErrors();
        }

        return $this->draft();
    }

    private function dom(string $html): DOMXPath
    {
        $document = new DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$html);

        return new DOMXPath($document);
    }

    public function test_malformed_setup_fields_show_validation_without_writing_records(): void
    {
        $before = $this->counts();
        $this->get('/admin/setup')->assertOk();
        $this->post('/admin/setup/duplicate', ['draft_token' => $this->draft()['token'], 'source' => 'invalid'])
            ->assertRedirect()->assertSessionHasErrors('source');
        $draft = $this->advanceTo(1);
        $this->post('/admin/setup/steps/2', ['draft_token' => $draft['token'], 'blocks' => [['name' => ['Invalid name']]]])
            ->assertRedirect()->assertSessionHasErrors('blocks.0.name');
        $this->post('/admin/setup/steps/2', ['draft_token' => $draft['token'], 'blocks' => 'invalid'])
            ->assertRedirect()->assertSessionHasErrors('blocks');
        $this->assertSame($before, $this->counts());
    }

    public function test_all_six_steps_render_and_no_records_change_before_confirmed_creation(): void
    {
        $before = $this->counts();
        $originalPlacement = Student::findOrFail(1)->block_id;
        $this->get('/admin/setup')->assertOk()->assertSee('Step 1: Academic Year')->assertSee('Duplicate Previous Setup');
        foreach (range(1, 5) as $step) {
            $this->post('/admin/setup/steps/'.$step, ['draft_token' => $this->draft()['token']] + $this->stepData($step))
                ->assertRedirect('/admin/setup?step='.($step + 1))->assertSessionHasNoErrors();
            $this->get('/admin/setup?step='.($step + 1))->assertOk()->assertSee('Step '.($step + 1).': '.AcademicSetup::STEPS[$step + 1])
                ->assertDontSee('@include(')->assertDontSee('@csrf')->assertDontSee('@if(');
            $this->assertSame($before, $this->counts());
            $this->assertSame($originalPlacement, Student::findOrFail(1)->block_id);
        }
        $review = $this->get('/admin/setup?step=6')->assertOk()->assertSee('Create Academic Setup')
            ->assertSee('4A, 4B')->assertSee('HTTP101')->assertSee('Demo Morgan Santos')
            ->assertViewHas('studentCount', 2)->assertViewHas('newAccountCount', 0);
        $draft = $review->viewData('draft');
        $payload = ['draft_token' => $draft['token'], 'review_hash' => $draft['review_hash']];
        $this->from('/admin/setup?step=6')->post('/admin/setup/create', $payload)
            ->assertRedirect('/admin/setup?step=6')->assertSessionHasErrors('confirm');
        $this->assertSame($before, $this->counts());
        $this->post('/admin/setup/create', $payload + ['confirm' => 1])
            ->assertRedirect('/admin/manage/teacher-assignments')->assertSessionHasNoErrors()->assertSessionHas('success')->assertSessionMissing('academic_setup');
        $after = $this->counts();
        $this->assertSame([$before[0], $before[1] + 2, $before[2] + 1, $before[3] + 4, $before[4], $before[5], $before[6] + 4, $before[7]], $after);
        $this->assertDatabaseHas('blocks', ['name' => '4A', 'semester' => 2]);
        $this->assertDatabaseHas('blocks', ['name' => '4B', 'semester' => 2]);
        $this->assertNotSame($originalPlacement, Student::findOrFail(1)->block_id);
        $this->assertDatabaseHas('enrollments', ['student_id' => 1, 'teacher_assignment_id' => 1]);
        $this->post('/admin/setup/create', $payload + ['confirm' => 1])->assertRedirect('/admin/manage/teacher-assignments');
        $this->assertSame($after, $this->counts());
    }

    public function test_steps_cannot_be_skipped_and_only_administrators_can_use_every_endpoint(): void
    {
        $before = $this->counts();
        $this->get('/admin/setup')->assertOk();
        $token = $this->draft()['token'];
        $this->get('/admin/setup?step=6')->assertRedirect('/admin/setup?step=1');
        $this->post('/admin/setup/steps/4', ['draft_token' => $token] + $this->stepData(4))->assertRedirect('/admin/setup?step=1');
        $this->assertSame(0, $this->draft()['completed']);
        $this->from('/admin/setup')->post('/admin/setup/create', ['draft_token' => $token, 'review_hash' => 'unreviewed', 'confirm' => 1])->assertSessionHasErrors('setup');
        foreach (['teacher', 'student'] as $role) {
            $this->actingAs($this->user($role));
            foreach (['/admin/setup', '/admin/setup?step=6', '/admin/setup/template'] as $url) {
                $this->get($url)->assertForbidden();
            }
            foreach (['/admin/setup/steps/1', '/admin/setup/duplicate', '/admin/setup/create', '/admin/setup/restart'] as $url) {
                $this->post($url, ['draft_token' => $token])->assertForbidden();
            }
        }
        $this->assertSame($before, $this->counts());
    }

    public function test_top_level_csv_errors_block_review_and_creation_until_csv_is_removed(): void
    {
        $this->advanceTo(5);
        $review = $this->get('/admin/setup?step=6')->assertOk()->viewData('draft');
        $before = $this->counts();
        $this->post('/admin/setup/steps/5', ['draft_token' => $review['token'], 'action' => 'preview', 'csv_file' => UploadedFile::fake()->createWithContent('invalid.csv', "Name,Email\nNew Student,newstudent@example.com\n")])
            ->assertRedirect('/admin/setup?step=5')->assertSessionHasNoErrors();
        $this->assertNotEmpty($this->draft()['csv_preview']['errors'][0]);
        $this->assertSame([], $this->draft()['csv_rows']);
        $this->get('/admin/setup?step=5')->assertOk()->assertSee('The CSV header must include');
        $this->get('/admin/setup?step=6')->assertRedirect('/admin/setup?step=5')->assertSessionHasErrors('csv');
        $this->from('/admin/setup?step=5')->post('/admin/setup/steps/5', ['draft_token' => $review['token'], 'action' => 'continue'])->assertSessionHasErrors('csv');
        $this->post('/admin/setup/create', ['draft_token' => $review['token'], 'review_hash' => $review['review_hash'], 'confirm' => 1])->assertSessionHasErrors('setup');
        $this->assertSame($before, $this->counts());
        $this->post('/admin/setup/steps/5', ['draft_token' => $review['token'], 'action' => 'clear_csv'])->assertRedirect('/admin/setup?step=5');
        $this->get('/admin/setup?step=6')->assertOk()->assertViewHas('studentCount', 2);
        $this->assertSame($before, $this->counts());
    }

    public function test_student_selection_is_paginated_and_saved_choices_survive_other_pages_and_searches(): void
    {
        for ($i = 0; $i < 24; $i++) {
            $user = User::create(['name' => 'Pagination Student '.$i, 'email' => 'pagination'.$i.'@example.com', 'password' => 'TestPassword123', 'role' => 'student', 'status' => 'active', 'email_verified_at' => now()]);
            Student::create(['user_id' => $user->id, 'student_number' => 'PAGE-'.str_pad($i, 3, '0', STR_PAD_LEFT), 'block_id' => 1]);
        }
        $this->advanceTo(4);
        $before = $this->counts();
        $token = $this->draft()['token'];
        $firstPage = $this->get('/admin/setup?step=5')->assertOk()->viewData('students');
        $this->assertSame(32, $firstPage->total());
        $this->assertSame(25, $firstPage->count());
        $this->assertStringContainsString('step=5', $firstPage->nextPageUrl());
        $first = $firstPage->first()->id;
        $this->post('/admin/setup/steps/5', ['draft_token' => $token, 'action' => 'selection', 'visible_ids' => $firstPage->pluck('id')->all(), 'selected' => [$first], 'placements' => [$first => 1]])
            ->assertRedirect('/admin/setup?step=5')->assertSessionHasNoErrors();
        $secondPage = $this->get('/admin/setup?step=5&page=2')->assertOk()->viewData('students');
        $this->assertSame(7, $secondPage->count());
        $second = $secondPage->last()->id;
        $this->post('/admin/setup/steps/5', ['draft_token' => $token, 'action' => 'selection', 'visible_ids' => $secondPage->pluck('id')->all(), 'selected' => [$second], 'placements' => [$second => 0]])
            ->assertRedirect('/admin/setup?step=5')->assertSessionHasNoErrors();
        $this->assertSame([['id' => $first, 'block' => 1], ['id' => $second, 'block' => 0]], $this->draft()['students']);
        $page = $this->get('/admin/setup?step=5')->assertOk();
        $dom = $this->dom($page->getContent());
        $this->assertSame(1, $dom->query('//input[@name="selected[]" and @value="'.$first.'" and @checked]')->length);
        $this->assertSame('1', $dom->query('//select[@name="placements['.$first.']"]/option[@selected]')->item(0)->getAttribute('value'));
        $this->get('/admin/setup?step=5&q=Pagination%20Student')->assertOk()->assertViewHas('students', fn ($students) => $students->total() === 24);
        $this->assertCount(2, $this->draft()['students']);
        $this->post('/admin/setup/steps/5', ['draft_token' => $token, 'action' => 'continue', 'visible_ids' => $firstPage->pluck('id')->all(), 'selected' => [$first], 'placements' => [$first => 1]])
            ->assertRedirect('/admin/setup?step=6')->assertSessionHasNoErrors();
        $this->get('/admin/setup?step=6')->assertOk()->assertViewHas('studentCount', 2);
        $this->assertSame($before, $this->counts());
    }

    public function test_duplicate_setup_prefill_survives_the_first_target_step_without_creating_records(): void
    {
        $before = $this->counts();
        $this->get('/admin/setup?duplicate=1')->assertOk();
        $oldToken = $this->draft()['token'];
        $this->post('/admin/setup/duplicate', ['draft_token' => $oldToken, 'source' => ['academic_year_id' => 1, 'program_id' => 1, 'year_level_id' => 1, 'semester' => 1], 'copy_students' => 1, 'copy_schedules' => 1])
            ->assertRedirect('/admin/setup')->assertSessionHasNoErrors();
        $copy = $this->draft();
        $this->assertNotSame($oldToken, $copy['token']);
        $this->assertSame(0, $copy['completed']);
        $this->assertCount(2, $copy['blocks']);
        $this->assertCount(6, $copy['subjects']);
        $this->assertCount(8, $copy['students']);
        $this->get('/admin/setup')->assertOk()->assertSee('Previous setup loaded');
        $this->post('/admin/setup/steps/1', ['draft_token' => $copy['token'], 'copy_schedules' => 1] + $this->stepData(1))
            ->assertRedirect('/admin/setup?step=2')->assertSessionHasNoErrors();
        $target = $this->draft();
        foreach (['blocks', 'subjects', 'assignments', 'students', 'source', 'source_block_ids'] as $key) {
            $this->assertSame($copy[$key], $target[$key]);
        }
        $this->assertTrue($target['copy_schedules']);
        $this->assertSame(2, $target['semester']);
        $this->get('/admin/setup?step=2')->assertOk()->assertSee('3J')->assertSee('3K');
        $this->assertSame($before, $this->counts());
    }

    public function test_restarted_drafts_reject_stale_tokens_and_clear_only_unsaved_state(): void
    {
        $draft = $this->advanceTo(3);
        $before = $this->counts();
        $this->post('/admin/setup/restart')->assertRedirect('/admin/setup')->assertSessionMissing('academic_setup');
        $this->get('/admin/setup')->assertOk();
        $this->assertNotSame($draft['token'], $this->draft()['token']);
        $this->from('/admin/setup')->post('/admin/setup/steps/1', ['draft_token' => $draft['token']] + $this->stepData(1))
            ->assertRedirect('/admin/setup')->assertSessionHasErrors('setup');
        $this->assertSame(0, $this->draft()['completed']);
        $this->assertSame($before, $this->counts());
    }

    public function test_editing_an_earlier_step_invalidates_review_and_downstream_choices(): void
    {
        $this->advanceTo(5);
        $review = $this->get('/admin/setup?step=6')->assertOk()->viewData('draft');
        $before = $this->counts();
        $this->post('/admin/setup/steps/2', ['draft_token' => $review['token'], 'blocks' => [['name' => 'Renamed block'], ['name' => 'Second block']]])
            ->assertRedirect('/admin/setup?step=3')->assertSessionHasNoErrors();
        $draft = $this->draft();
        $this->assertSame(2, $draft['completed']);
        foreach (['review_hash', 'subjects', 'assignments', 'students', 'csv_rows'] as $key) {
            $this->assertArrayNotHasKey($key, $draft);
        }
        $this->get('/admin/setup?step=6')->assertRedirect('/admin/setup?step=3');
        $this->post('/admin/setup/create', ['draft_token' => $review['token'], 'review_hash' => $review['review_hash'], 'confirm' => 1])->assertSessionHasErrors('setup');
        $this->assertSame($before, $this->counts());
    }
}
