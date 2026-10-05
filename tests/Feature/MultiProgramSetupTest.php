<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Block;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\Program;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\TeacherAssignment;
use App\Models\User;
use App\Models\YearLevel;
use App\Services\AcademicSetup;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class MultiProgramSetupTest extends TestCase
{
    use DatabaseMigrations;

    private array $programs;

    private array $teachers;

    private array $students;

    private array $subjects;

    private YearLevel $thirdYear;

    private TeacherAssignment $oldClass;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->actingAs($this->account('admin', 'admin'));
        $this->programs = [];
        foreach (['BSIT' => 'BS Information Technology', 'BSBA' => 'BS Business Administration', 'BSED' => 'BS Education'] as $code => $name) {
            $this->programs[] = Program::create(compact('code', 'name'));
        }
        $firstYear = YearLevel::create(['name' => '1st Year', 'level' => 1]);
        $this->thirdYear = YearLevel::create(['name' => '3rd Year', 'level' => 3]);
        $year = AcademicYear::create(['name' => '2025-2026', 'starts_on' => '2025-06-01', 'ends_on' => '2026-04-30']);
        $oldBlock = Block::create(['academic_year_id' => $year->id, 'program_id' => $this->programs[0]->id, 'year_level_id' => $firstYear->id, 'semester' => 1, 'name' => 'Old Block']);
        $this->teachers = [];
        foreach (range(0, 2) as $index) {
            $this->teachers[] = Teacher::create(['user_id' => $this->account('teacher'.$index, 'teacher')->id, 'employee_number' => 'MULTI-T'.$index]);
        }
        $this->subjects = [];
        foreach (['IT300', 'IT200', 'BUS300', 'EDU300'] as $code) {
            $this->subjects[] = Subject::create(['code' => $code, 'name' => $code.' Subject', 'units' => 3, 'status' => 'active']);
        }
        $this->oldClass = TeacherAssignment::create(['block_id' => $oldBlock->id, 'subject_id' => $this->subjects[0]->id, 'teacher_id' => $this->teachers[0]->id]);
        Lesson::create(['teacher_assignment_id' => $this->oldClass->id, 'title' => 'Preserved historical lesson', 'content' => 'Academic history', 'position' => 1, 'status' => 'published']);
        $this->students = [];
        foreach (range(0, 2) as $index) {
            $student = Student::create(['user_id' => $this->account('student'.$index, 'student')->id, 'student_number' => 'MULTI-S'.$index, 'block_id' => $oldBlock->id]);
            Enrollment::create(['student_id' => $student->id, 'teacher_assignment_id' => $this->oldClass->id]);
            $this->students[] = $student;
        }
    }

    private function account(string $name, string $role): User
    {
        return tap((new User)->forceFill(['name' => 'Multi Program '.$name, 'email' => $name.'@example.com', 'password' => 'TestPassword123', 'role' => $role, 'status' => 'active', 'email_verified_at' => now()]), fn ($user) => $user->save());
    }

    private function year(): array
    {
        return ['academic_year_name' => '2026-2027', 'starts_on' => '2026-06-01', 'ends_on' => '2027-04-30', 'semester' => 1];
    }

    private function structures(): array
    {
        $existing = fn ($names) => ['year_level_id' => $this->thirdYear->id, 'blocks' => array_map(fn ($name) => ['name' => $name], $names)];
        $new = fn ($names) => ['year_level_id' => null, 'name' => '2nd Year', 'level' => 2, 'blocks' => array_map(fn ($name) => ['name' => $name], $names)];

        return [
            ['program_id' => $this->programs[0]->id, 'year_levels' => [$existing(['3J', '3K']), $new(['2A', '2B'])]],
            ['program_id' => $this->programs[1]->id, 'year_levels' => [$existing(['3A', '3B']), $new(['2A', '2B'])]],
            ['program_id' => $this->programs[2]->id, 'year_levels' => [$existing(['3A', '3B'])]],
        ];
    }

    private function activities(): array
    {
        return [
            'subjects' => array_map(fn ($subject) => $subject->only(['id', 'code', 'name', 'units']), $this->subjects),
            'assignments' => [
                ['subject' => 0, 'teacher_id' => $this->teachers[0]->id, 'blocks' => [0, 1]],
                ['subject' => 1, 'teacher_id' => $this->teachers[1]->id, 'blocks' => [2, 3]],
                ['subject' => 2, 'teacher_id' => $this->teachers[2]->id, 'blocks' => [4, 5, 6, 7]],
                ['subject' => 3, 'teacher_id' => $this->teachers[1]->id, 'blocks' => [8, 9]],
            ],
            'students' => [], 'csv_rows' => [],
        ];
    }

    private function counts(): array
    {
        return collect(['academic_years', 'year_levels', 'blocks', 'subjects', 'teacher_assignments', 'users', 'students', 'enrollments', 'lessons', 'jobs'])
            ->mapWithKeys(fn ($table) => [$table => DB::table($table)->count()])->all();
    }

    private function token(): string
    {
        return session('academic_setup.token');
    }

    public function test_complete_multi_program_wizard_preserves_scopes_enrollments_and_role_access(): void
    {
        $before = $this->counts();
        $originalUsers = array_map(fn ($student) => $student->user->getAttributes(), $this->students);
        $this->get('/admin/setup')->assertOk();
        $this->post('/admin/setup/steps/1', ['draft_token' => $this->token()] + $this->year())
            ->assertRedirect('/admin/setup?step=2')->assertSessionHasNoErrors();
        $this->get('/admin/setup?step=2')->assertOk()->assertSee('Program')->assertSee('Year Level');
        $this->post('/admin/setup/steps/2', ['draft_token' => $this->token(), 'structures' => $this->structures()])
            ->assertRedirect('/admin/setup?step=3')->assertSessionHasNoErrors();
        $this->assertCount(10, session('academic_setup.blocks'));
        $this->assertSame($before, $this->counts());
        $activities = $this->activities();
        foreach ([3 => 'subjects', 4 => 'assignments'] as $step => $field) {
            $this->post('/admin/setup/steps/'.$step, ['draft_token' => $this->token(), $field => $activities[$field]])
                ->assertRedirect('/admin/setup?step='.($step + 1))->assertSessionHasNoErrors();
        }
        $studentPage = $this->get('/admin/setup?step=5')->assertOk();
        foreach (['BSIT', 'BSBA', 'BSED', '2A', '3A'] as $label) {
            $studentPage->assertSee($label);
        }
        $csv = UploadedFile::fake()->createWithContent('students.csv', "Student ID,Name,Email,Program,Year Level,Block\nMULTI-S1,Existing Student,student1@example.com,BSBA,2,2A\n");
        $this->post('/admin/setup/steps/5', ['draft_token' => $this->token(), 'action' => 'preview', 'csv_file' => $csv,
            'visible_ids' => [$this->students[0]->id, $this->students[2]->id], 'selected' => [$this->students[0]->id, $this->students[2]->id],
            'placements' => [$this->students[0]->id => 2, $this->students[2]->id => 8]])
            ->assertRedirect('/admin/setup?step=5')->assertSessionHasNoErrors();
        $this->assertSame(6, session('academic_setup.csv_rows.0.block_index'));
        $this->assertSame($before, $this->counts());
        $this->post('/admin/setup/steps/5', ['draft_token' => $this->token(), 'action' => 'continue'])
            ->assertRedirect('/admin/setup?step=6')->assertSessionHasNoErrors();
        $review = $this->get('/admin/setup?step=6')->assertOk()->assertViewHas('studentCount', 3);
        foreach ($this->programs as $program) {
            $review->assertSee($program->name);
        }
        $this->assertSame($before, $this->counts());
        $draft = $review->viewData('draft');
        $this->post('/admin/setup/create', ['draft_token' => $draft['token'], 'review_hash' => $draft['review_hash'], 'confirm' => 1])
            ->assertRedirect('/admin/manage/teacher-assignments')->assertSessionHasNoErrors();
        $year = AcademicYear::where('name', '2026-2027')->firstOrFail();
        $secondYear = YearLevel::where('level', 2)->firstOrFail();
        $this->assertDatabaseCount('year_levels', 3);
        $this->assertSame('2nd Year', $secondYear->name);
        $expected = [
            [0, 3, ['3J', '3K']], [0, 2, ['2A', '2B']], [1, 3, ['3A', '3B']], [1, 2, ['2A', '2B']], [2, 3, ['3A', '3B']],
        ];
        foreach ($expected as [$programIndex, $level, $names]) {
            $blocks = Block::where('academic_year_id', $year->id)->where('program_id', $this->programs[$programIndex]->id)
                ->whereHas('yearLevel', fn ($q) => $q->where('level', $level))->pluck('name')->all();
            $this->assertEqualsCanonicalizing($names, $blocks);
        }
        $this->assertSame(10, Block::where('academic_year_id', $year->id)->count());
        foreach ([0 => [0, 2, '2A', 1, 1], 1 => [1, 2, '2A', 2, 2], 2 => [2, 3, '3A', 3, 1]] as $index => [$programIndex, $level, $name, $subjectIndex, $teacherIndex]) {
            $student = $this->students[$index]->fresh();
            $this->assertSame($this->programs[$programIndex]->id, $student->block->program_id);
            $this->assertSame($level, $student->block->yearLevel->level);
            $this->assertSame($name, $student->block->name);
            $class = $student->block->classes->sole();
            $this->assertSame($this->subjects[$subjectIndex]->id, $class->subject_id);
            $this->assertSame($this->teachers[$teacherIndex]->id, $class->teacher_id);
            $this->assertDatabaseHas('enrollments', ['student_id' => $student->id, 'teacher_assignment_id' => $class->id]);
            $this->assertDatabaseHas('enrollments', ['student_id' => $student->id, 'teacher_assignment_id' => $this->oldClass->id]);
            $this->assertSame($originalUsers[$index], $student->user->getAttributes());
        }
        $this->assertDatabaseHas('lessons', ['teacher_assignment_id' => $this->oldClass->id, 'title' => 'Preserved historical lesson']);
        $businessClass = $this->students[1]->fresh()->block->classes->sole();
        $this->actingAs($this->students[0]->user);
        $this->get('/classes/'.$businessClass->id)->assertForbidden();
        $this->get('/admin/setup')->assertForbidden();
        $this->actingAs($this->teachers[0]->user);
        $this->get('/classes/'.$businessClass->id)->assertForbidden();
        $this->actingAs($this->teachers[2]->user);
        $this->get('/classes/'.$businessClass->id)->assertOk();
    }

    public function test_invalid_scoped_names_or_decimal_year_levels_create_no_records(): void
    {
        $this->get('/admin/setup')->assertOk();
        $this->post('/admin/setup/steps/1', ['draft_token' => $this->token()] + $this->year())->assertSessionHasNoErrors();
        $before = $this->counts();
        $duplicate = $this->structures();
        $duplicate[0]['year_levels'][0]['blocks'] = [['name' => '3J'], ['name' => '3j']];
        $this->post('/admin/setup/steps/2', ['draft_token' => $this->token(), 'structures' => $duplicate])->assertSessionHasErrors();
        $decimal = $this->structures();
        $decimal[0]['year_levels'][1]['level'] = '2.01';
        $this->post('/admin/setup/steps/2', ['draft_token' => $this->token(), 'structures' => $decimal])->assertSessionHasErrors();
        $this->assertSame($before, $this->counts());
        $this->assertSame(1, session('academic_setup.completed'));
    }

    public function test_collision_in_one_program_rolls_back_the_entire_setup(): void
    {
        $year = AcademicYear::create(['name' => '2026-2027', 'starts_on' => '2026-06-01', 'ends_on' => '2027-04-30']);
        Block::create(['academic_year_id' => $year->id, 'program_id' => $this->programs[2]->id, 'year_level_id' => $this->thirdYear->id, 'semester' => 1, 'name' => '3A']);
        $before = $this->counts();
        try {
            AcademicSetup::create(['academic_year_id' => $year->id, 'semester' => 1, 'structures' => $this->structures()] + $this->activities());
            $this->fail('The occupied block must prevent the entire setup.');
        } catch (ValidationException $exception) {
            $this->assertNotEmpty($exception->errors());
        }
        $this->assertSame($before, $this->counts());
        $this->assertDatabaseMissing('year_levels', ['level' => 2]);
        Notification::assertNothingSent();
    }

    public function test_scoped_template_and_truncated_structure_counts_are_validated(): void
    {
        $this->get('/admin/setup/template?scoped=1')->assertOk()->assertSee('program,year_level,block');
        $this->get('/admin/setup')->assertOk();
        $this->post('/admin/setup/steps/1', ['draft_token' => $this->token()] + $this->year())->assertSessionHasNoErrors();
        $before = $this->counts();
        $this->post('/admin/setup/steps/2', ['draft_token' => $this->token(), 'expected_structure_count' => 3, 'structures' => array_slice($this->structures(), 0, 2)])
            ->assertSessionHasErrors();
        $this->assertSame($before, $this->counts());
    }

    public function test_malformed_nested_old_input_renders_validation_instead_of_a_server_error(): void
    {
        $this->get('/admin/setup')->assertOk();
        $this->post('/admin/setup/steps/1', ['draft_token' => $this->token()] + $this->year())->assertSessionHasNoErrors();
        $before = $this->counts();
        $invalid = [['program_id' => ['invalid'], 'year_levels' => [['year_level_id' => ['invalid'], 'name' => ['invalid'], 'level' => ['invalid'], 'blocks' => [['name' => ['invalid']]]]]]];
        $this->from('/admin/setup?step=2')->post('/admin/setup/steps/2', ['draft_token' => $this->token(), 'structures' => $invalid])
            ->assertRedirect('/admin/setup?step=2')->assertSessionHasErrors();
        $this->get('/admin/setup?step=2')->assertOk()->assertSee('Please check the following:')->assertDontSee('Server Error');
        $this->assertSame($before, $this->counts());
    }
}
