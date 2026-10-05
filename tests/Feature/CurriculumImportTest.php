<?php

namespace Tests\Feature;

use App\Models\Block;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\TeacherAssignment;
use App\Models\User;
use App\Models\YearLevel;
use App\Services\AcademicSetup;
use App\Services\CurriculumImport;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CurriculumImportTest extends TestCase
{
    use RefreshDatabase;

    private function rows(): array
    {
        return app(CurriculumImport::class)->read(database_path('data/UEP_demo_courses_import.csv'));
    }

    private function import(): array
    {
        return app(CurriculumImport::class)->run($this->rows());
    }

    private function subject(array $row): Subject
    {
        return Subject::whereHas('program', fn ($q) => $q->where('code', $row['program_code']))
            ->whereHas('yearLevel', fn ($q) => $q->where('level', $row['level']))
            ->where('semester', $row['semester_number'])->where('code', $row['course_code'])->sole();
    }

    private function protectedData(): array
    {
        return collect(['users', 'blocks', 'academic_years', 'students', 'teachers', 'teacher_assignments', 'enrollments', 'class_schedules', 'lessons', 'learning_materials', 'assignments', 'assignment_submissions', 'quizzes', 'grades', 'attendance_records', 'announcements'])
            ->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all();
    }

    public function test_entire_source_is_preserved_and_prerequisites_resolve_to_same_program_ids(): void
    {
        $this->seed();
        $before = $this->protectedData();
        $legacy = Subject::whereNull('program_id')->orderBy('id')->get()->map->getAttributes()->all();
        $firstYear = YearLevel::where('level', 1)->first()->getAttributes();
        $rows = $this->rows();
        $result = $this->import();
        $this->assertSame(10, $result['programs']);
        $this->assertSame(4, $result['year_levels']);
        $this->assertSame(221, $result['courses_created']);
        $this->assertSame(133, $result['prerequisites_created']);
        $this->assertDatabaseCount('programs', 10);
        $this->assertDatabaseCount('subject_prerequisites', 133);
        foreach ($rows as $row) {
            $subject = $this->subject($row);
            $this->assertSame($row['course_code'], $subject->code);
            $this->assertSame($row['course_title'], $subject->name);
            foreach (['units', 'lecture_units', 'laboratory_units'] as $field) {
                $this->assertSame($row[$field], $subject->$field);
            }
            $this->assertSame($row['prerequisite_codes'], $subject->prerequisites->pluck('code')->all());
            foreach ($subject->prerequisites as $prerequisite) {
                $this->assertSame($subject->program_id, $prerequisite->program_id);
            }
        }
        $this->assertSame($before, $this->protectedData());
        $this->assertSame($legacy, Subject::whereNull('program_id')->orderBy('id')->get()->map->getAttributes()->all());
        $this->assertSame($firstYear, YearLevel::where('level', 1)->first()->getAttributes());
        // Conflicting codes have independent titles, credits and prerequisite IDs.
        $this->assertSame(4, Subject::where('code', 'OJT 401')->count());
        $this->assertSame(3, Subject::where('code', 'RES 301')->count());
    }

    public function test_second_import_is_unchanged_and_dry_run_writes_nothing(): void
    {
        $before = $this->protectedData();
        $dry = app(CurriculumImport::class)->run($this->rows(), true);
        $this->assertSame(221, $dry['courses_created']);
        $this->assertDatabaseCount('programs', 0);
        $this->assertDatabaseCount('subjects', 0);
        $this->assertSame($before, $this->protectedData());
        $this->import();
        $attributes = Subject::orderBy('id')->get()->map->getAttributes()->all();
        $second = $this->import();
        $this->assertSame(0, $second['courses_created']);
        $this->assertSame(0, $second['courses_updated']);
        $this->assertSame(221, $second['courses_unchanged']);
        $this->assertSame(0, $second['prerequisites_created']);
        $this->assertSame($attributes, Subject::orderBy('id')->get()->map->getAttributes()->all());
    }

    public function test_matching_legacy_course_is_adopted_without_changing_its_class_or_description(): void
    {
        $this->seed();
        $row = collect($this->rows())->first(fn ($row) => $row['program_code'] === 'BSIT' && $row['course_code'] === 'SIA 101');
        $block = Block::first();
        $this->assertSame($row['level'], $block->yearLevel->level);
        $this->assertSame($row['semester_number'], $block->semester);
        $legacy = Subject::create(['code' => $row['course_code'], 'name' => $row['course_title'], 'units' => $row['units'], 'status' => 'inactive', 'description' => 'Preserve existing course notes']);
        $class = TeacherAssignment::create(['block_id' => $block->id, 'teacher_id' => Teacher::first()->id, 'subject_id' => $legacy->id]);
        $this->import();
        $this->assertSame($legacy->id, $this->subject($row)->id);
        $this->assertSame($legacy->id, $class->fresh()->subject_id);
        $this->assertSame('inactive', $legacy->fresh()->status);
        $this->assertSame('Preserve existing course notes', $legacy->fresh()->description);
    }

    public function test_shared_catalog_and_curriculum_duplicates_are_blocked_by_database_constraints(): void
    {
        Subject::create(['code' => 'SHARED', 'name' => 'Shared course', 'units' => 3, 'status' => 'active']);
        try {
            Subject::create(['code' => 'SHARED', 'name' => 'Duplicate shared course', 'units' => 3, 'status' => 'active']);
            $this->fail('Shared course code must stay unique.');
        } catch (UniqueConstraintViolationException $e) {
            $this->assertDatabaseCount('subjects', 1);
        }
        $this->import();
        $subject = $this->subject($this->rows()[0]);
        $this->expectException(UniqueConstraintViolationException::class);
        Subject::create($subject->only(['code', 'name', 'units', 'status', 'program_id', 'year_level_id', 'semester']));
    }

    public function test_validation_rejects_missing_prerequisites_duplicate_rows_units_and_cycles_without_writing(): void
    {
        $base = array_slice($this->rows(), 0, 2);
        $variants = [];
        $missing = $base;
        $missing[0]['prerequisite'] = 'MISSING';
        $variants[] = $missing;
        $variants[] = [$base[0], $base[0]];
        $units = $base;
        $units[0]['units'] = '3.5';
        $variants[] = $units;
        $cycle = $base;
        $cycle[0]['prerequisite'] = $cycle[1]['course_code'];
        $cycle[1]['prerequisite'] = $cycle[0]['course_code'];
        $variants[] = $cycle;
        foreach ($variants as $rows) {
            $path = tempnam(sys_get_temp_dir(), 'curriculum');
            $file = fopen($path, 'w');
            $headers = ['program_code', 'year_level', 'semester', 'course_code', 'course_title', 'units', 'lecture_units', 'laboratory_units', 'prerequisite'];
            fputcsv($file, $headers);
            foreach ($rows as $row) {
                fputcsv($file, array_map(fn ($header) => $row[$header], $headers));
            }
            fclose($file);
            try {
                app(CurriculumImport::class)->read($path);
                $this->fail('Invalid source must be rejected.');
            } catch (\RuntimeException $e) {
                $this->assertNotEmpty($e->getMessage());
            } finally {
                unlink($path);
            }
        }
        $this->assertDatabaseCount('subjects', 0);
        $this->assertDatabaseCount('programs', 0);
    }

    public function test_unexpected_existing_prerequisite_rolls_back_all_updates_without_deleting_links(): void
    {
        $this->import();
        $subject = $this->subject($this->rows()[0]);
        $other = $this->subject($this->rows()[1]);
        $subject->prerequisites()->attach($other->id);
        $subject->update(['name' => 'Previously edited title']);
        try {
            $this->import();
            $this->fail('Unexpected prerequisites require review.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Existing prerequisites differ', $e->getMessage());
        }
        $this->assertSame('Previously edited title', $subject->fresh()->name);
        $this->assertTrue($subject->prerequisites()->whereKey($other->id)->exists());
        $this->assertDatabaseCount('subject_prerequisites', 134);
    }

    public function test_admin_catalog_and_wizard_render_scoped_courses_and_validate_credits(): void
    {
        $this->seed();
        $this->import();
        $this->actingAs(User::where('role', 'admin')->first());
        $this->get('/admin/manage/programs')->assertOk()->assertSee('BSMID');
        $course = Subject::whereHas('program', fn ($q) => $q->where('code', 'BSHRM'))->where('code', 'OJT 401')->sole();
        $this->get('/admin/manage/subjects?program_id='.$course->program_id)->assertOk()->assertSee('Hospitality Internship')->assertSee('Prerequisites')->assertSee('HM 401');
        $this->get('/admin/manage/subjects/'.$course->id.'/edit')->assertOk()->assertSee('Lecture Units')->assertSee('Laboratory Units')->assertSee('HM 401');
        $fields = $course->only(['code', 'name', 'units', 'status', 'program_id', 'year_level_id', 'semester', 'lecture_units', 'laboratory_units']);
        $this->put('/admin/manage/subjects/'.$course->id, array_replace($fields, ['units' => '6.5']))->assertSessionHasErrors('units');
        $this->put('/admin/manage/subjects/'.$course->id, array_replace($fields, ['units' => '6.5', 'laboratory_units' => '6.5']))->assertSessionHasNoErrors();
        $this->assertSame('6.50', $course->fresh()->units);
        $this->get('/admin/setup')->assertOk();
        $draft = session('academic_setup');
        $this->withSession(['academic_setup' => array_replace($draft, ['completed' => 2, 'semester' => 2, 'blocks' => [['program_id' => $course->program_id, 'year_level_id' => $course->year_level_id]]])])
            ->get('/admin/setup?step=3')->assertOk()->assertSee('BSHRM / '.$course->yearLevel->name.' / S2')->assertSee('Hospitality Internship')->assertDontSee('Accounting Internship');
        $this->actingAs(User::where('role', 'student')->first())->get('/admin/manage/subjects')->assertForbidden();
        $this->actingAs(User::where('role', 'teacher')->first())->get('/admin/setup')->assertForbidden();
    }

    public function test_wizard_uses_selected_ids_for_shared_codes_and_rejects_wrong_program_assignments(): void
    {
        $this->seed();
        $this->import();
        $subjects = Subject::where('code', 'OJT 401')->whereHas('program', fn ($q) => $q->whereIn('code', ['BSIT', 'BSBA']))->get();
        $draft = ['academic_year_name' => 'Curriculum Test Year', 'starts_on' => '2028-06-01', 'ends_on' => '2029-04-01', 'semester' => 2,
            'structures' => $subjects->map(fn ($s) => ['program_id' => $s->program_id, 'year_levels' => [['year_level_id' => $s->year_level_id, 'blocks' => [['name' => 'A']]]]])->all(),
            'subjects' => $subjects->map(fn ($s) => $s->only(['id', 'code', 'name', 'units']))->all(),
            'assignments' => $subjects->map(fn ($s, $i) => ['subject' => $i, 'teacher_id' => Teacher::first()->id, 'blocks' => [$i]])->all(), 'students' => [], 'csv_rows' => []];
        try {
            AcademicSetup::normalizeStep(3, ['subjects' => [['code' => 'OJT 401', 'name' => 'Incorrect guess', 'units' => 6]]]);
            $this->fail('Ambiguous manual code must be rejected.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('subjects', $e->errors());
        }
        $wrong = $draft;
        $wrong['assignments'][0]['blocks'] = [1];
        try {
            AcademicSetup::validateDraft($wrong);
            $this->fail('Wrong program must be rejected.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('assignments.0.blocks', $e->errors());
        }
        $result = AcademicSetup::create($draft);
        $this->assertSame(2, $result['classes']);
        foreach ($subjects as $subject) {
            $class = TeacherAssignment::where('subject_id', $subject->id)->sole();
            $this->assertSame($subject->program_id, $class->block->program_id);
            $this->assertSame($subject->year_level_id, $class->block->year_level_id);
        }
    }
}
