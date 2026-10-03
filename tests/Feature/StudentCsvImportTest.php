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
        return User::create(['name' => 'Existing Name', 'email' => $email, 'password' => 'OriginalPassword123', 'role' => $role, 'status' => 'active', 'email_verified_at' => now()]);
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
}
