<?php

namespace App\Services;

use App\Jobs\SendSetupInvitation;
use App\Models\AcademicYear;
use App\Models\Block;
use App\Models\ClassSchedule;
use App\Models\Enrollment;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\TeacherAssignment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AcademicSetup
{
    public const STEPS = [1 => 'Academic Year', 2 => 'Blocks', 3 => 'Subjects', 4 => 'Teachers', 5 => 'Students', 6 => 'Review'];

    public static function rules(int $step): array
    {
        return match ($step) {
            1 => [
                'academic_year_id' => 'nullable|integer|exists:academic_years,id',
                'academic_year_name' => 'required_without:academic_year_id|nullable|string|max:120',
                'starts_on' => 'required_without:academic_year_id|nullable|date',
                'ends_on' => 'required_without:academic_year_id|nullable|date|after:starts_on',
                'semester' => 'required|integer|in:1,2,3', 'program_id' => 'required|integer|exists:programs,id', 'year_level_id' => 'required|integer|exists:year_levels,id',
            ],
            2 => ['blocks' => 'required|array|min:1|max:30', 'blocks.*.name' => 'bail|required|string|max:120|distinct:ignore_case'],
            3 => ['subjects' => 'required|array|min:1|max:50', 'subjects.*.id' => 'nullable|integer', 'subjects.*.code' => 'bail|required|string|max:120|distinct:ignore_case', 'subjects.*.name' => 'required|string|max:120', 'subjects.*.units' => 'required|integer|min:1|max:12'],
            4 => ['assignments' => 'required|array|min:1|max:100', 'assignments.*.subject' => 'required|integer|min:0', 'assignments.*.teacher_id' => 'required|integer', 'assignments.*.blocks' => 'required|array|min:1|max:30', 'assignments.*.blocks.*' => 'required|integer|min:0'],
            default => [],
        };
    }

    public static function normalizeStep(int $step, array $input): array
    {
        foreach (['blocks', 'subjects', 'assignments'] as $field) {
            if (isset($input[$field]) && (! is_array($input[$field]) || count(array_filter($input[$field], 'is_array')) !== count($input[$field]))) {
                throw ValidationException::withMessages([$field => 'Provide a valid list of setup rows.']);
            }
        }
        if ($step === 2) {
            $input['blocks'] = array_values(array_map(fn ($row) => ['name' => is_string($row['name'] ?? null) ? trim($row['name']) : ($row['name'] ?? '')], $input['blocks'] ?? []));
        }
        if ($step === 3) {
            $input['subjects'] = array_values(array_map(fn ($row) => ['id' => $row['id'] ?? null, 'code' => is_string($row['code'] ?? null) ? trim($row['code']) : ($row['code'] ?? ''), 'name' => is_string($row['name'] ?? null) ? trim($row['name']) : ($row['name'] ?? ''), 'units' => $row['units'] ?? null], $input['subjects'] ?? []));
        }
        if ($step === 4) {
            $input['assignments'] = array_values($input['assignments'] ?? []);
        }
        $data = Validator::make($input, self::rules($step), [], ['academic_year_name' => 'academic year name'])->validate();
        if ($step === 1 && ! ($data['academic_year_id'] ?? null) && AcademicYear::where('name', trim($data['academic_year_name']))->exists()) {
            throw ValidationException::withMessages(['academic_year_name' => 'This academic year already exists. Select it from Existing Academic Year.']);
        }
        if ($step === 3) {
            $ids = array_filter(array_column($data['subjects'], 'id'));
            $codes = array_map(fn ($row) => Str::lower($row['code']), $data['subjects']);
            $existing = Subject::whereIn('id', $ids)->orWhereIn(DB::raw('LOWER(code)'), $codes)->get();
            foreach ($data['subjects'] as &$row) {
                $subject = $row['id'] ? $existing->firstWhere('id', $row['id']) : $existing->first(fn ($item) => Str::lower($item->code) === Str::lower($row['code']));
                if ($row['id'] && ! $subject) {
                    throw ValidationException::withMessages(['subjects' => 'A selected subject is no longer available. Select it again.']);
                }
                if ($subject) {
                    // Reuse the existing subject; setup never rewrites shared subject details.
                    $row = ['id' => $subject->id, 'code' => $subject->code, 'name' => $subject->name, 'units' => $subject->units];
                }
            }
            unset($row);
            if (count(array_unique(array_map(fn ($row) => Str::lower($row['code']), $data['subjects']))) !== count($data['subjects'])) {
                throw ValidationException::withMessages(['subjects' => 'Select each subject only once. Existing subject codes are reused automatically.']);
            }
        }

        return $data;
    }

    public static function validateDraft(array $draft): array
    {
        $data = [];
        for ($step = 1; $step <= 4; $step++) {
            $data = array_merge($data, self::normalizeStep($step, $draft));
        }
        $pairs = [];
        $teachers = Teacher::whereIn('id', array_column($data['assignments'], 'teacher_id'))->with('user')->get()->keyBy('id');
        foreach ($data['assignments'] as $index => $row) {
            $teacherUser = $teachers->get($row['teacher_id'])?->user;
            if (! isset($data['subjects'][$row['subject']]) || ! $teacherUser || $teacherUser->role !== 'teacher') {
                throw ValidationException::withMessages(["assignments.$index.teacher_id" => 'Choose an existing teacher account and a subject from this setup.']);
            }
            foreach ($row['blocks'] as $blockIndex) {
                if (! isset($data['blocks'][$blockIndex])) {
                    throw ValidationException::withMessages(["assignments.$index.blocks" => 'Select only blocks included in this setup.']);
                }
                $key = $blockIndex.':'.$row['subject'];
                if (isset($pairs[$key])) {
                    throw ValidationException::withMessages(['assignments' => 'Each block and subject must have only one assigned teacher.']);
                }
                $pairs[$key] = true;
            }
        }
        if (count($pairs) > 300) {
            throw ValidationException::withMessages(['assignments' => 'Create no more than 300 classes in one setup.']);
        }
        $students = $draft['students'] ?? [];
        Validator::make(['students' => $students], ['students' => 'array|max:500', 'students.*.id' => 'required|integer|distinct', 'students.*.block' => 'required|integer|min:0'])->validate();
        $existingStudents = Student::whereIn('id', array_column($students, 'id'))->with('user')->get()->keyBy('id');
        foreach ($students as $row) {
            $studentUser = $existingStudents->get($row['id'])?->user;
            if (! isset($data['blocks'][$row['block']]) || ! $studentUser || $studentUser->role !== 'student') {
                throw ValidationException::withMessages(['students' => 'Every selected student needs an available student account and a block from this setup.']);
            }
        }
        $csv = empty($draft['csv_rows']) ? ['rows' => [], 'errors' => []] : StudentCsvImport::validateRows($draft['csv_rows'], array_column($data['blocks'], 'name'));
        if ($csv['errors']) {
            throw ValidationException::withMessages(['csv' => 'The CSV preview contains invalid or conflicting rows. Correct it before creating the setup.']);
        }
        if (count($students) + count($csv['rows']) > 500) {
            throw ValidationException::withMessages(['students' => 'Enroll at most 500 students per setup.']);
        }
        $selectedIds = array_column($students, 'id');
        foreach ($csv['rows'] as $row) {
            if ($row['existing_student_id'] && in_array($row['existing_student_id'], $selectedIds)) {
                throw ValidationException::withMessages(['students' => 'A student appears in both the checkbox selection and the CSV. Use one enrollment option for each student.']);
            }
        }
        $data['students'] = array_values($students);
        $data['csv_rows'] = $csv['rows'];
        $data['copy_schedules'] = (bool) ($draft['copy_schedules'] ?? false);
        $data['source'] = empty($draft['source']) ? null : Validator::make($draft['source'], ['academic_year_id' => 'required|integer|exists:academic_years,id', 'semester' => 'required|integer|in:1,2,3', 'program_id' => 'required|integer|exists:programs,id', 'year_level_id' => 'required|integer|exists:year_levels,id'])->validate();
        $classCounts = array_count_values(array_map(fn ($pair) => explode(':', $pair)[0], array_keys($pairs)));
        $enrollmentCount = array_sum(array_map(fn ($row) => $classCounts[$row['block']] ?? 0, $students));
        $blockIndexes = array_flip(array_map(fn ($name) => Str::lower($name), array_column($data['blocks'], 'name')));
        foreach ($csv['rows'] as $row) {
            $enrollmentCount += $classCounts[$blockIndexes[Str::lower($row['block'])]] ?? 0;
        }
        if ($enrollmentCount > 10000) {
            throw ValidationException::withMessages(['students' => 'This setup would create more than 10,000 enrollments. Split enrollment into smaller batches.']);
        }

        return $data;
    }

    public static function duplicate(array $scope, bool $copyStudents, bool $copySchedules): array
    {
        $scope = Validator::make($scope, ['academic_year_id' => 'required|integer|exists:academic_years,id', 'program_id' => 'required|integer|exists:programs,id', 'year_level_id' => 'required|integer|exists:year_levels,id', 'semester' => 'required|integer|in:1,2,3'])->validate();
        $blocks = Block::where($scope)->with(['classes.subject', 'classes.teacher.user'])->orderBy('id')->limit(31)->get();
        if ($blocks->isEmpty() || $blocks->count() > 30) {
            throw ValidationException::withMessages(['source' => 'Choose a previous setup with 1–30 blocks.']);
        }
        $subjects = $blocks->flatMap->classes->pluck('subject')->unique('id')->values();
        if ($subjects->isEmpty() || $subjects->count() > 50) {
            throw ValidationException::withMessages(['source' => 'The previous setup needs subjects and supports at most 50 per copy.']);
        }
        $subjectIndexes = $subjects->pluck('id')->flip();
        $assignments = [];
        foreach ($blocks as $blockIndex => $block) {
            foreach ($block->classes as $class) {
                $key = $class->subject_id.':'.$class->teacher_id;
                $assignments[$key] ??= ['subject' => $subjectIndexes[$class->subject_id], 'teacher_id' => $class->teacher_id, 'blocks' => []];
                $assignments[$key]['blocks'][] = $blockIndex;
            }
        }
        $students = [];
        if ($copyStudents) {
            $people = Student::whereIn('block_id', $blocks->pluck('id'))->whereHas('user', fn ($q) => $q->where('role', 'student'))->limit(501)->get(['id', 'block_id']);
            if ($people->count() > 500) {
                throw ValidationException::withMessages(['source' => 'Copy at most 500 students per setup. You can enroll further students through the existing management pages.']);
            }
            $blockIndexes = $blocks->pluck('id')->flip();
            $students = $people->map(fn ($student) => ['id' => $student->id, 'block' => $blockIndexes[$student->block_id]])->all();
        }

        return [
            'blocks' => $blocks->map(fn ($block) => ['name' => $block->name])->all(),
            'subjects' => $subjects->map(fn ($subject) => ['id' => $subject->id, 'code' => $subject->code, 'name' => $subject->name, 'units' => $subject->units])->all(),
            'assignments' => array_values($assignments), 'students' => $students,
            'source' => $scope, 'source_block_ids' => $blocks->pluck('id')->all(), 'copy_schedules' => $copySchedules,
        ];
    }

    public static function create(array $draft): array
    {
        return DB::transaction(function () use ($draft) {
            $data = self::validateDraft($draft);
            $year = ($data['academic_year_id'] ?? null)
                ? AcademicYear::whereKey($data['academic_year_id'])->lockForUpdate()->firstOrFail()
                : AcademicYear::create(['name' => trim($data['academic_year_name']), 'starts_on' => $data['starts_on'], 'ends_on' => $data['ends_on']]);
            if (Block::where('academic_year_id', $year->id)->where('program_id', $data['program_id'])->where('year_level_id', $data['year_level_id'])->where('semester', $data['semester'])->whereIn(DB::raw('LOWER(name)'), array_map(fn ($row) => Str::lower($row['name']), $data['blocks']))->exists()) {
                throw ValidationException::withMessages(['blocks' => 'One or more target blocks already exist for this academic year, semester, program and year level. Change the names or correct them on the management pages.']);
            }
            // These catalog models have no creation hooks. Batch writes avoid one
            // database round trip for every block, subject and assigned class.
            $now = now();
            $blockScope = ['academic_year_id' => $year->id, 'program_id' => $data['program_id'], 'year_level_id' => $data['year_level_id'], 'semester' => $data['semester']];
            Block::insert(array_map(fn ($row) => $blockScope + ['name' => $row['name'], 'created_at' => $now, 'updated_at' => $now], $data['blocks']));
            $blockMap = Block::where($blockScope)->whereIn('name', array_column($data['blocks'], 'name'))->get()->keyBy('name');
            $blocks = array_map(fn ($row) => $blockMap->get($row['name']), $data['blocks']);
            $newSubjects = array_filter($data['subjects'], fn ($row) => ! $row['id']);
            if ($newSubjects) {
                Subject::insert(array_map(fn ($row) => ['code' => $row['code'], 'name' => $row['name'], 'units' => $row['units'], 'status' => 'active', 'created_at' => $now, 'updated_at' => $now], array_values($newSubjects)));
            }
            $subjectMap = Subject::whereIn('code', array_column($data['subjects'], 'code'))->get()->keyBy('code');
            $subjects = array_map(fn ($row) => $subjectMap->get($row['code']), $data['subjects']);
            $classRows = [];
            foreach ($data['assignments'] as $row) {
                foreach ($row['blocks'] as $blockIndex) {
                    $classRows[] = ['teacher_id' => $row['teacher_id'], 'block_id' => $blocks[$blockIndex]->id, 'subject_id' => $subjects[$row['subject']]->id, 'created_at' => $now, 'updated_at' => $now];
                }
            }
            TeacherAssignment::insert($classRows);
            $classes = [];
            $blockIndexes = array_flip(array_map(fn ($block) => $block->id, $blocks));
            foreach (TeacherAssignment::whereIn('block_id', array_keys($blockIndexes))->get() as $class) {
                $index = $blockIndexes[$class->block_id];
                $class->setRelation('block', $blocks[$index]);
                $classes[$index.':'.$class->subject_id] = $class;
            }
            $scheduleCount = self::copySchedules($data, $draft, $blocks, $classes);
            [$studentCount, $newUsers, $enrollmentCount] = self::enroll($data, $blocks, $classes);

            return ['academic_year_id' => $year->id, 'blocks' => count($blocks), 'subjects' => count($subjects), 'classes' => count($classes), 'students' => $studentCount, 'new_users' => count($newUsers), 'invitations_queued' => count($newUsers), 'enrollments' => $enrollmentCount, 'schedules' => $scheduleCount];
        }, 3);
    }

    private static function copySchedules(array $data, array $draft, array $blocks, array $classes): int
    {
        if (! $data['copy_schedules'] || ! $data['source']) {
            return 0;
        }
        // Re-query the source scope; client fields and stale source IDs cannot select other records.
        $sourceBlocks = Block::where($data['source'])->orderBy('id')->limit(30)->pluck('id')->all();
        $sourceIds = $draft['source_block_ids'] ?? $sourceBlocks;
        if (! is_array($sourceIds) || count($sourceIds) !== count($blocks) || count(array_unique($sourceIds)) !== count($sourceIds) || array_diff($sourceIds, $sourceBlocks)) {
            throw ValidationException::withMessages(['schedules' => 'Copied blocks changed. Load the previous setup again or turn off Copy Schedules before saving.']);
        }
        $mapping = array_flip($sourceIds);
        $schedules = ClassSchedule::whereHas('classroom', fn ($q) => $q->whereIn('block_id', $sourceBlocks))->with('classroom')->orderBy('id')->limit(301)->get();
        if ($schedules->count() > 300) {
            throw ValidationException::withMessages(['schedules' => 'Copy at most 300 schedule entries per setup.']);
        }
        $count = 0;
        foreach ($schedules as $schedule) {
            $index = $mapping[$schedule->classroom->block_id] ?? null;
            $class = $index === null ? null : ($classes[$index.':'.$schedule->classroom->subject_id] ?? null);
            if (! $class) {
                continue;
            }
            $row = $schedule->only(['day', 'start_time', 'end_time', 'room']);
            if (ScheduleConflicts::exists($class, $row)) {
                throw ValidationException::withMessages(['schedules' => 'A copied schedule conflicts with a teacher, block or room in the target semester. Turn off Copy Schedules and set the times on the existing schedule page. Nothing was created.']);
            }
            ClassSchedule::create($row + ['teacher_assignment_id' => $class->id]);
            $count++;
        }

        return $count;
    }

    private static function enroll(array $data, array $blocks, array $classes): array
    {
        $now = now();
        $blockIndexes = array_flip(array_map(fn ($block) => Str::lower($block->name), $blocks));
        $selection = [];
        $studentIds = array_column($data['students'], 'id');
        $studentIds = array_merge($studentIds, array_filter(array_column($data['csv_rows'], 'existing_student_id')));
        $existingStudents = Student::whereIn('id', $studentIds)->lockForUpdate()->get()->keyBy('id');
        foreach ($data['students'] as $row) {
            $person = $existingStudents[$row['id']];
            $selection[] = ['student_id' => $person->id, 'user_id' => $person->user_id, 'student_number' => $person->student_number, 'block' => $row['block']];
        }
        $newRows = array_filter($data['csv_rows'], fn ($row) => ! $row['existing_user_id']);
        if ($newRows) {
            // Inactive accounts cannot sign in. Their unusable random secret is replaced
            // by the user's own normally hashed password during existing activation.
            $unusableHash = Hash::make(Str::random(64));
            User::insert(array_map(fn ($row) => ['name' => $row['name'], 'email' => $row['email'], 'password' => $unusableHash, 'role' => 'student', 'status' => 'inactive', 'created_at' => $now, 'updated_at' => $now], array_values($newRows)));
        }
        $users = User::whereIn(DB::raw('LOWER(email)'), array_map(fn ($row) => Str::lower($row['email']), $data['csv_rows']))->get()->keyBy(fn ($user) => Str::lower($user->email));
        $newUserIds = [];
        foreach ($data['csv_rows'] as $row) {
            $user = $users[Str::lower($row['email'])];
            if (! $row['existing_user_id']) {
                $newUserIds[] = $user->id;
            }
            $selection[] = ['student_id' => $row['existing_student_id'], 'user_id' => $user->id, 'student_number' => $row['student_number'], 'block' => $blockIndexes[Str::lower($row['block'])]];
        }
        $newProfiles = [];
        $moves = [];
        foreach ($selection as $row) {
            $values = ['user_id' => $row['user_id'], 'student_number' => $row['student_number'], 'block_id' => $blocks[$row['block']]->id, 'created_at' => $now, 'updated_at' => $now];
            if ($row['student_id']) {
                $values['id'] = $row['student_id'];
                $values['created_at'] = $existingStudents[$row['student_id']]->created_at;
                $moves[] = $values;
            } else {
                $newProfiles[] = $values;
            }
        }
        if ($newProfiles) {
            Student::insert($newProfiles);
        }
        if ($moves) {
            Student::upsert($moves, ['id'], ['block_id', 'updated_at']);
        }
        $studentMap = Student::whereIn('user_id', array_column($selection, 'user_id'))->get(['id', 'user_id'])->keyBy('user_id');
        $enrollments = [];
        foreach ($selection as $row) {
            foreach ($classes as $class) {
                if ($class->block_id === $blocks[$row['block']]->id) {
                    $enrollments[] = ['student_id' => $studentMap[$row['user_id']]->id, 'teacher_assignment_id' => $class->id, 'created_at' => $now, 'updated_at' => $now];
                }
            }
        }
        foreach (array_chunk($enrollments, 300) as $chunk) {
            Enrollment::insert($chunk);
        }
        if ($newUserIds) {
            // Existing jobs table and worker: one database insert, no SMTP loop in a request.
            Queue::connection('database')->bulk(array_map(fn ($id) => new SendSetupInvitation($id), $newUserIds));
        }

        return [count($selection), $newUserIds, count($enrollments)];
    }
}
