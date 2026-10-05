<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Block;
use App\Models\Program;
use App\Models\Student;
use App\Models\User;
use App\Models\YearLevel;
use App\Services\StudentCsvImport;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StudentCsvImportTest extends TestCase
{
    use DatabaseMigrations;

    private function csv(string $body): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('students.csv', $body);
    }

    private function account(string $email, string $role = 'student'): User
    {
        return tap((new User)->forceFill(['name' => 'Existing Name', 'email' => $email, 'password' => 'OriginalPassword123', 'role' => $role, 'status' => 'active', 'email_verified_at' => now()]), fn ($user) => $user->save());
    }

    private function student(User $user, string $number = '2026-001'): Student
    {
        $year = AcademicYear::firstOrCreate(['name' => '2026-2027'], ['starts_on' => '2026-06-01', 'ends_on' => '2027-04-30']);
        $program = Program::firstOrCreate(['code' => 'BSIT'], ['name' => 'Information Technology']);
        $level = YearLevel::firstOrCreate(['level' => 1], ['name' => 'First Year']);
        $block = Block::firstOrCreate(['name' => 'Block A', 'academic_year_id' => $year->id, 'program_id' => $program->id, 'year_level_id' => $level->id, 'semester' => 1]);

        return Student::create(['user_id' => $user->id, 'student_number' => $number, 'block_id' => $block->id]);
    }

    public function test_preview_normalizes_new_and_existing_students_without_any_writes(): void
    {
        Notification::fake();
        Storage::fake('local');
        $user = $this->account('known@example.com');
        $student = $this->student($user);
        $before = $user->fresh()->getAttributes();
        DB::enableQueryLog();
        DB::flushQueryLog();

        $result = StudentCsvImport::preview($this->csv("student_id,name,email,block\n2026-001,Changed Name,KNOWN@example.com,block a\n2026-002,New Student,new@example.com,Block A\n"), ['Block A']);

        $this->assertSame([], $result['errors']);
        $this->assertSame(2, $result['valid_count']);
        $this->assertSame(0, $result['invalid_count']);
        $this->assertSame('existing', $result['rows'][0]['action']);
        $this->assertSame($student->id, $result['rows'][0]['existing_student_id']);
        $this->assertSame($user->id, $result['rows'][0]['existing_user_id']);
        $this->assertSame('Block A', $result['rows'][0]['block']);
        $this->assertSame('known@example.com', $result['rows'][0]['email']);
        $this->assertSame('new', $result['rows'][1]['action']);
        $this->assertNull($result['rows'][1]['existing_user_id']);
        $queries = DB::getQueryLog();
        $this->assertLessThanOrEqual(3, count($queries));
        $this->assertTrue(collect($queries)->every(fn ($query) => str_starts_with(strtolower($query['query']), 'select')));
        DB::disableQueryLog();
        $this->assertSame($before, $user->fresh()->getAttributes());
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('students', 1);
        $this->assertDatabaseCount('enrollments', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
        Notification::assertNothingSent();
    }

    public function test_student_role_account_without_profile_can_be_reused(): void
    {
        $user = $this->account('unplaced@example.com');
        $result = StudentCsvImport::preview($this->csv("Student ID,Name,Email,Block\nS-100,Imported Name,unplaced@example.com,Block A\n"), ['Block A']);

        $this->assertSame([], $result['errors']);
        $this->assertSame('existing', $result['rows'][0]['action']);
        $this->assertSame($user->id, $result['rows'][0]['existing_user_id']);
        $this->assertNull($result['rows'][0]['existing_student_id']);
        $this->assertSame('Existing Name', $user->fresh()->name);
        $this->assertDatabaseCount('students', 0);
    }

    public function test_utf8_bom_and_quoted_friendly_headers_are_supported(): void
    {
        $result = StudentCsvImport::preview($this->csv("\xEF\xBB\xBF\"Student ID\",\"Name\",\"Email\",\"Block\"\r\n001,\"Name, With Comma\",student@example.com,Block A\r\n"), ['Block A']);

        $this->assertSame([], $result['errors']);
        $this->assertSame('001', $result['rows'][0]['student_number']);
        $this->assertSame('Name, With Comma', $result['rows'][0]['name']);
        $this->assertSame(2, $result['rows'][0]['row']);
    }

    public function test_missing_duplicate_and_empty_headers_are_rejected(): void
    {
        foreach (["student_id,name,email\n1,Name,a@example.com\n", "student_id,name,email,block,EMAIL\n1,Name,a@example.com,Block A,a@example.com\n", "student_id,name,email,block,\n1,Name,a@example.com,Block A,\n"] as $csv) {
            $result = StudentCsvImport::preview($this->csv($csv), ['Block A']);
            $this->assertNotEmpty($result['errors'][0]);
            $this->assertSame(0, $result['valid_count']);
        }
    }

    public function test_invalid_values_report_the_csv_row_without_creating_accounts(): void
    {
        $result = StudentCsvImport::preview($this->csv("student_id,name,email,block\n,Name,not-email,Unknown\nS-2,,valid@example.com,Block A\nS-3,".str_repeat('a', 121).",valid2@example.com,Block A\n"), ['Block A']);

        $this->assertSame(0, $result['valid_count']);
        $this->assertSame(3, $result['invalid_count']);
        $this->assertArrayHasKey(2, $result['errors']);
        $this->assertArrayHasKey(3, $result['errors']);
        $this->assertArrayHasKey(4, $result['errors']);
        $this->assertFalse($result['rows'][0]['valid']);
        $this->assertStringContainsString('Block must match', implode(' ', $result['errors'][2]));
        $this->assertDatabaseCount('users', 0);
    }

    public function test_duplicates_are_case_insensitive_and_mark_both_rows_invalid(): void
    {
        $result = StudentCsvImport::preview($this->csv("student_id,name,email,block\nS-1,One,one@example.com,Block A\ns-1,Two,ONE@example.com,Block A\n"), ['Block A']);

        $this->assertSame(0, $result['valid_count']);
        $this->assertSame(2, $result['invalid_count']);
        $this->assertCount(2, $result['errors'][2]);
        $this->assertCount(2, $result['errors'][3]);
    }

    public function test_archived_and_non_student_accounts_cannot_be_imported(): void
    {
        $archived = $this->account('archived@example.com');
        $this->student($archived);
        $archived->delete();
        $this->account('teacher@example.com', 'teacher');
        $result = StudentCsvImport::preview($this->csv("student_id,name,email,block\n2026-001,Archived,archived@example.com,Block A\nS-2,Teacher,teacher@example.com,Block A\n"), ['Block A']);

        $this->assertSame(2, $result['invalid_count']);
        $this->assertStringContainsString('archived', implode(' ', $result['errors'][2]));
        $this->assertStringContainsString('different role', implode(' ', $result['errors'][3]));
        $this->assertDatabaseCount('students', 1);
    }

    public function test_student_number_and_email_must_identify_the_same_student(): void
    {
        $user = $this->account('known@example.com');
        $this->student($user);
        $result = StudentCsvImport::preview($this->csv("student_id,name,email,block\n2026-001,Wrong Email,other@example.com,Block A\nDIFFERENT-ID,Wrong ID,known@example.com,Block A\n"), ['Block A']);

        $this->assertSame(2, $result['invalid_count']);
        $this->assertStringContainsString('different email', implode(' ', $result['errors'][2]));
        $this->assertStringContainsString('different Student ID', implode(' ', $result['errors'][3]));
    }

    public function test_wrong_width_unclosed_quotes_empty_files_and_non_csv_files_are_rejected(): void
    {
        foreach (["student_id,name,email,block\n1,Only Two\n", "student_id,name,email,block\n1,\"Unclosed,a@example.com,Block A\n", "student_id,name,email,block\n", ''] as $csv) {
            $result = StudentCsvImport::preview($this->csv($csv), ['Block A']);
            $this->assertNotEmpty($result['errors']);
            $this->assertSame(0, $result['valid_count']);
        }
        $result = StudentCsvImport::preview(UploadedFile::fake()->createWithContent('students.txt', StudentCsvImport::template()), ['Block A']);
        $this->assertNotEmpty($result['errors'][0]);
    }

    public function test_file_and_row_limits_are_enforced(): void
    {
        $result = StudentCsvImport::preview($this->csv(str_repeat('a', StudentCsvImport::MAX_BYTES + 1)), ['Block A']);
        $this->assertNotEmpty($result['errors'][0]);
        $csv = "student_id,name,email,block\n";
        for ($i = 1; $i <= 501; $i++) {
            $csv .= "S-$i,Student $i,student$i@example.com,Block A\n";
        }
        $result = StudentCsvImport::preview($this->csv($csv), ['Block A']);
        $this->assertCount(500, $result['rows']);
        $this->assertStringContainsString('500', implode(' ', $result['errors'][0]));
        $this->assertDatabaseCount('users', 0);
    }

    public function test_commit_revalidation_ignores_posted_ids_and_detects_fresh_database_conflicts(): void
    {
        $rows = [['row' => 2, 'student_number' => 'S-1', 'name' => 'New Student', 'email' => 'new@example.com', 'block' => 'Block A', 'existing_user_id' => 123, 'existing_student_id' => 456, 'action' => 'existing']];
        $first = StudentCsvImport::validateRows($rows, ['Block A']);
        $this->assertSame([], $first['errors']);
        $this->assertSame('new', $first['rows'][0]['action']);
        $this->assertNull($first['rows'][0]['existing_user_id']);
        $this->assertNull($first['rows'][0]['existing_student_id']);
        $this->account('new@example.com', 'admin');
        $second = StudentCsvImport::validateRows($first['rows'], ['Block A']);
        $this->assertSame(1, $second['invalid_count']);
        $this->assertStringContainsString('different role', implode(' ', $second['errors'][2]));
        $this->assertDatabaseCount('students', 0);
    }

    public function test_canonical_rows_limits_and_ambiguous_target_blocks_are_rejected(): void
    {
        $row = ['student_number' => 'S-1', 'name' => 'Name', 'email' => 'one@example.com', 'block' => 'Block A'];
        $result = StudentCsvImport::validateRows([$row], ['Block A', 'block a']);
        $this->assertNotEmpty($result['errors'][0]);
        $this->assertNotEmpty(StudentCsvImport::validateRows([], ['Block A'])['errors'][0]);
        $result = StudentCsvImport::validateRows(array_fill(0, 501, $row), ['Block A']);
        $this->assertCount(500, $result['rows']);
        $this->assertNotEmpty($result['errors'][0]);
        $row['name'] = str_repeat('x', StudentCsvImport::MAX_BYTES + 1);
        $this->assertNotEmpty(StudentCsvImport::validateRows([$row], ['Block A'])['errors'][0]);
        $this->assertStringContainsString('student@example.com', StudentCsvImport::template());
    }

    private function scopedBlocks(): array
    {
        $blocks = [];
        foreach ([['BSIT', 'Information Technology', 2, 'Second Year', '2A'], ['BSBA', 'Business Administration', 2, 'Second Year', '2A'], ['BSIT', 'Information Technology', 3, 'Third Year', '2A'], ['BSBA', 'Business Administration', 3, 'Third Year', '2A'], ['BSIT', 'Information Technology', 2, 'Second Year', '2B']] as $index => [$program, $programName, $level, $yearLevel, $name]) {
            $blocks[$index] = ['index' => $index, 'name' => $name, 'program' => $program, 'program_name' => $programName, 'year_level' => $yearLevel, 'level' => $level, 'label' => $program.' — '.$yearLevel.' — Block '.$name];
        }

        return $blocks;
    }

    public function test_scoped_csv_resolves_repeated_block_names_by_program_and_year_level_without_writes(): void
    {
        Notification::fake();
        Storage::fake('local');
        DB::enableQueryLog();
        DB::flushQueryLog();
        $csv = "\xEF\xBB\xBFStudent ID,Name,Email,Program,Year Level,Block\n"
            ."S-1,IT Second,it2@example.com, bsit ,2,2a\n"
            ."S-2,BA Second,ba2@example.com,Business Administration,second year,2A\n"
            ."S-3,IT Third,it3@example.com,Information Technology,Third Year,2A\n"
            ."S-4,BA Third,ba3@example.com,bsba,3,2A\n"
            ."S-5,Unique Block,unique@example.com,,,2B\n";
        $result = StudentCsvImport::preview($this->csv($csv), $this->scopedBlocks());
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertSame([], $result['errors']);
        $this->assertSame(5, $result['valid_count']);
        $this->assertSame([0, 1, 2, 3, 4], array_column($result['rows'], 'block_index'));
        $this->assertSame(['2A', '2A', '2A', '2A', '2B'], array_column($result['rows'], 'block'));
        $this->assertSame('bsit', $result['rows'][0]['program']);
        $this->assertSame('2', $result['rows'][0]['year_level']);
        $this->assertSame('BSIT — Second Year — Block 2A', $result['rows'][0]['block_label']);
        $this->assertLessThanOrEqual(3, count($queries));
        $this->assertTrue(collect($queries)->every(fn ($query) => str_starts_with(strtolower($query['query']), 'select')));
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('students', 0);
        $this->assertDatabaseCount('enrollments', 0);
        $this->assertDatabaseCount('jobs', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
        Notification::assertNothingSent();
    }

    public function test_unique_descriptor_name_supports_legacy_csv_and_preserves_target_index(): void
    {
        $target = $this->scopedBlocks()[4];
        $target['index'] = 17;
        $result = StudentCsvImport::preview($this->csv("student_id,name,email,block\nS-1,Unique,unique@example.com,2b\n"), [17 => $target]);

        $this->assertSame([], $result['errors']);
        $this->assertSame(17, $result['rows'][0]['block_index']);
        $this->assertSame('', $result['rows'][0]['program']);
        $this->assertSame('', $result['rows'][0]['year_level']);
        $legacy = StudentCsvImport::preview($this->csv("student_id,name,email,block\nS-1,Unique,unique@example.com,Block B\n"), ['Block A', 'Block B']);
        $this->assertSame(1, $legacy['rows'][0]['block_index']);
        $this->assertSame('Block B', $legacy['rows'][0]['block_label']);
    }

    public function test_ambiguous_scoped_block_requires_filters_and_lists_actual_choices(): void
    {
        foreach (["student_id,name,email,block\nS-1,Ambiguous,a@example.com,2A\n", "student_id,name,email,program,year_level,block\nS-1,Ambiguous,a@example.com,BSIT,,2A\n", "student_id,name,email,program,year_level,block\nS-1,Ambiguous,a@example.com,,2,2A\n"] as $csv) {
            $result = StudentCsvImport::preview($this->csv($csv), $this->scopedBlocks());
            $this->assertSame(0, $result['valid_count']);
            $this->assertSame(1, $result['invalid_count']);
            $this->assertNull($result['rows'][0]['block_index']);
            $message = implode(' ', $result['errors'][2]);
            $this->assertStringContainsString('ambiguous', $message);
            $this->assertStringContainsString('Program and Year Level', $message);
            $this->assertStringContainsString('BSIT — Second Year — Block 2A', $message);
        }
        $result = StudentCsvImport::preview($this->csv("student_id,name,email,block\nS-1,Ambiguous,a@example.com,2A\n"), $this->scopedBlocks());
        $this->assertStringContainsString('BSBA — Third Year — Block 2A', implode(' ', $result['errors'][2]));
    }

    public function test_wrong_scoped_filters_never_fall_back_to_another_target(): void
    {
        foreach ([['BSCS', '2', '2A'], ['BSIT', '4', '2A'], ['BSIT', '2.0', '2A'], ['BSIT', 'First Year', '2A'], ['BSBA', '2', '2B']] as [$program, $level, $block]) {
            $result = StudentCsvImport::preview($this->csv("student_id,name,email,program,year_level,block\nS-1,Mismatch,mismatch@example.com,$program,$level,$block\n"), $this->scopedBlocks());
            $this->assertSame(0, $result['valid_count']);
            $this->assertNull($result['rows'][0]['block_index']);
            $this->assertSame('', $result['rows'][0]['block_label']);
            $this->assertStringContainsString('does not match', implode(' ', $result['errors'][2]));
        }
        $result = StudentCsvImport::preview($this->csv("student_id,name,email,program,year_level,block\nS-1,Mismatch,mismatch@example.com,BSIT,2,missing\n"), $this->scopedBlocks());
        $this->assertStringContainsString('Block must match', implode(' ', $result['errors'][2]));
    }

    public function test_scoped_revalidation_recomputes_block_index_label_and_account_ids(): void
    {
        $row = ['row' => 2, 'student_number' => 'S-1', 'name' => 'Student', 'email' => 'student@example.com', 'program' => 'BSIT', 'year_level' => 'Second Year', 'block' => '2A', 'block_index' => 3, 'block_label' => 'Forged placement', 'existing_user_id' => 999, 'existing_student_id' => 999];
        $result = StudentCsvImport::validateRows([$row], $this->scopedBlocks());
        $this->assertSame([], $result['errors']);
        $this->assertSame(0, $result['rows'][0]['block_index']);
        $this->assertSame('BSIT — Second Year — Block 2A', $result['rows'][0]['block_label']);
        $this->assertNull($result['rows'][0]['existing_user_id']);
        $this->assertNull($result['rows'][0]['existing_student_id']);
        $row['program'] = 'BSBA';
        $row['year_level'] = '3';
        $row['block_index'] = 0;
        $result = StudentCsvImport::validateRows([$row], $this->scopedBlocks());
        $this->assertSame(3, $result['rows'][0]['block_index']);
        $row['program'] = '';
        $row['year_level'] = '';
        $result = StudentCsvImport::validateRows([$row], $this->scopedBlocks());
        $this->assertNull($result['rows'][0]['block_index']);
        $this->assertNotEmpty($result['errors'][2]);
    }

    public function test_duplicate_malformed_mixed_or_oversized_target_scopes_are_rejected(): void
    {
        $row = ['student_number' => 'S-1', 'name' => 'Student', 'email' => 'student@example.com', 'program' => 'BSIT', 'year_level' => '2', 'block' => '2A'];
        $blocks = $this->scopedBlocks();
        $duplicate = $blocks[0];
        $duplicate['index'] = 5;
        $duplicate['name'] = '2a';
        $this->assertNotEmpty(StudentCsvImport::validateRows([$row], [$blocks[0], $duplicate])['errors'][0]);
        $duplicate = $blocks[1];
        $duplicate['index'] = 0;
        $this->assertNotEmpty(StudentCsvImport::validateRows([$row], [$blocks[0], $duplicate])['errors'][0]);
        $this->assertNotEmpty(StudentCsvImport::validateRows([$row], ['2A', $blocks[0]])['errors'][0]);
        $malformed = $blocks[0];
        unset($malformed['program']);
        $this->assertNotEmpty(StudentCsvImport::validateRows([$row], [$malformed])['errors'][0]);
        $this->assertNotEmpty(StudentCsvImport::validateRows([$row], array_fill(0, StudentCsvImport::MAX_BLOCKS + 1, $blocks[0]))['errors'][0]);
        $row['program'] = ['BSIT'];
        $this->assertNotEmpty(StudentCsvImport::validateRows([$row], $blocks)['errors'][2]);
    }

    public function test_scoped_columns_are_included_in_size_and_length_validation_and_template_is_safe(): void
    {
        $row = ['student_number' => 'S-1', 'name' => 'Student', 'email' => 'student@example.com', 'program' => str_repeat('x', 121), 'year_level' => str_repeat('x', 121), 'block' => '2A'];
        $result = StudentCsvImport::validateRows([$row], $this->scopedBlocks());
        $this->assertNotEmpty($result['errors'][2]);
        $this->assertStringContainsString('120', implode(' ', $result['errors'][2]));
        $row['program'] = str_repeat('x', StudentCsvImport::MAX_BYTES + 1);
        $this->assertNotEmpty(StudentCsvImport::validateRows([$row], $this->scopedBlocks())['errors'][0]);
        $this->assertSame(StudentCsvImport::template(), StudentCsvImport::template(false));
        $this->assertStringStartsWith("student_id,name,email,program,year_level,block\r\n", StudentCsvImport::template(true));
        $result = StudentCsvImport::preview($this->csv(StudentCsvImport::template(true)), $this->scopedBlocks());
        $this->assertSame([], $result['errors']);
        $this->assertSame(0, $result['rows'][0]['block_index']);
        $this->assertSame('student@example.com', $result['rows'][0]['email']);
        $this->assertDatabaseCount('users', 0);
    }
}
