<?php

namespace App\Services;

use App\Jobs\SendSetupInvitation;
use App\Models\AcademicYear;
use App\Models\Block;
use App\Models\ClassSchedule;
use App\Models\Enrollment;
use App\Models\Program;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\TeacherAssignment;
use App\Models\User;
use App\Models\YearLevel;
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
                'semester' => 'required|integer|in:1,2,3', 'program_id' => 'nullable|integer|exists:programs,id', 'year_level_id' => 'nullable|integer|exists:year_levels,id',
            ],
            2 => ['blocks' => 'required|array|min:1|max:30', 'blocks.*.name' => 'bail|required|string|max:120|distinct:ignore_case'],
            3 => ['subjects' => 'required|array|min:1|max:50', 'subjects.*.id' => 'nullable|integer', 'subjects.*.code' => 'bail|required|string|max:120', 'subjects.*.name' => 'required|string|max:120', 'subjects.*.units' => 'required|numeric|decimal:0,2|gt:0|max:12'],
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
            return self::normalizeStep2($input);
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
            $existing = Subject::with(['program', 'yearLevel'])->whereIn('id', $ids)->orWhereIn(DB::raw('LOWER(code)'), $codes)->get();
            foreach ($data['subjects'] as &$row) {
                $matches = $existing->filter(fn ($item) => Str::lower($item->code) === Str::lower($row['code']));
                if (! $row['id'] && $matches->count() > 1) {
                    throw ValidationException::withMessages(['subjects' => 'This course code belongs to multiple curricula. Select the exact Program / Year Level / Semester from Existing Subject.']);
                }
                $subject = $row['id'] ? $existing->firstWhere('id', $row['id']) : $matches->first();
                if ($row['id'] && ! $subject) {
                    throw ValidationException::withMessages(['subjects' => 'A selected subject is no longer available. Select it again.']);
                }
                if ($subject) {
                    // Reuse the existing subject; setup never rewrites shared subject details.
                    $row = ['id' => $subject->id, 'code' => $subject->code, 'name' => $subject->name, 'units' => $subject->units, 'label' => $subject->catalog_label];
                }
            }
            unset($row);
            if (count(array_unique(array_map(fn ($row) => $row['id'] ? 'id:'.$row['id'] : 'code:'.Str::lower($row['code']), $data['subjects']))) !== count($data['subjects'])) {
                throw ValidationException::withMessages(['subjects' => 'Select each subject only once. Existing subject codes are reused automatically.']);
            }
        }

        return $data;
    }

    public static function normalizeStep2(array $input): array
    {
        if (! array_key_exists('structures', $input)) {
            $rows = $input['blocks'] ?? [];
            if (! is_array($rows) || count(array_filter($rows, 'is_array')) !== count($rows)) {
                throw ValidationException::withMessages(['blocks' => 'Provide a valid list of setup rows.']);
            }
            $blocks = array_values(array_map(fn ($row) => ['name' => is_string($row['name'] ?? null) ? trim($row['name']) : ($row['name'] ?? '')], $rows));
            $data = Validator::make(['blocks' => $blocks], self::rules(2))->validate();
            // Old step requests contain names only; their saved Step 1 scope is added on review.
            if (! array_key_exists('program_id', $input) && ! array_key_exists('year_level_id', $input)) {
                return $data;
            }
            $scope = Validator::make($input, ['program_id' => 'required|integer', 'year_level_id' => 'required|integer'])->validate();
            $input['structures'] = [['program_id' => $scope['program_id'], 'year_levels' => [['year_level_id' => $scope['year_level_id'], 'blocks' => $blocks]]]];
        }
        $validated = Validator::make($input, [
            'structures' => 'required|array|min:1|max:20',
            'structures.*' => 'required|array:program_id,year_levels,expected_level_count',
            'structures.*.program_id' => 'required|integer|distinct',
            'structures.*.year_levels' => 'required|array|min:1|max:12',
            'structures.*.year_levels.*' => 'required|array:year_level_id,name,level,blocks,expected_block_count',
            'structures.*.year_levels.*.year_level_id' => 'nullable|integer',
            'structures.*.year_levels.*.name' => 'nullable|string|max:120',
            'structures.*.year_levels.*.level' => 'nullable|integer|min:1|max:12',
            'structures.*.year_levels.*.blocks' => 'required|array|min:1|max:30',
            'structures.*.year_levels.*.blocks.*' => 'required|array:name',
            'structures.*.year_levels.*.blocks.*.name' => 'required|string|max:120',
        ])->validate();
        self::checkStructureCounts($input);
        $groups = array_values($validated['structures']);
        $programs = Program::whereIn('id', array_column($groups, 'program_id'))->get(['id', 'code', 'name'])->keyBy('id');
        $levels = YearLevel::get(['id', 'name', 'level']);
        $byId = $levels->keyBy('id');
        $byOrdinal = $levels->keyBy('level');
        $newNames = [];
        $newLevels = [];
        $blocks = [];
        $keys = [];
        foreach ($groups as $groupIndex => &$group) {
            if (! $programs->has($group['program_id'])) {
                throw ValidationException::withMessages(["structures.$groupIndex.program_id" => 'Select an existing program.']);
            }
            $group['program_id'] = (int) $group['program_id'];
            $group['year_levels'] = array_values($group['year_levels']);
            $seenLevels = [];
            foreach ($group['year_levels'] as $levelIndex => &$level) {
                $field = "structures.$groupIndex.year_levels.$levelIndex";
                $existing = ! empty($level['year_level_id']) ? $byId->get($level['year_level_id']) : null;
                if (! empty($level['year_level_id']) && ! $existing) {
                    throw ValidationException::withMessages([$field.'.year_level_id' => 'Select an existing year level.']);
                }
                if (! $existing) {
                    $newValidator = Validator::make(['name' => is_string($level['name'] ?? null) ? trim($level['name']) : ($level['name'] ?? null), 'level' => $level['level'] ?? null],
                        ['name' => 'required|string|max:120', 'level' => 'required|integer|min:1|max:12']);
                    if ($newValidator->fails()) {
                        throw ValidationException::withMessages(collect($newValidator->errors()->messages())->mapWithKeys(fn ($messages, $key) => [$field.'.'.$key => $messages])->all());
                    }
                    $new = $newValidator->validated();
                    $existing = $byOrdinal->get($new['level']);
                    if (! $existing) {
                        $ordinal = (int) $new['level'];
                        $name = $newLevels[$ordinal] ?? $new['name'];
                        if ($levels->contains(fn ($row) => Str::lower($row->name) === Str::lower($name)) || isset($newNames[Str::lower($name)]) && $newNames[Str::lower($name)] !== $ordinal) {
                            throw ValidationException::withMessages([$field.'.name' => 'This year level name belongs to another level number. Select the existing year level or use a different name.']);
                        }
                        $newLevels[$ordinal] = $name;
                        $newNames[Str::lower($name)] = $ordinal;
                    }
                }
                $ordinal = $existing ? (int) $existing->level : (int) $new['level'];
                if (isset($seenLevels[$ordinal])) {
                    throw ValidationException::withMessages([$field.'.year_level_id' => 'Add each year level only once per program. Add its blocks to the existing group.']);
                }
                $seenLevels[$ordinal] = true;
                $names = array_values(array_map(fn ($row) => ['name' => trim($row['name'])], $level['blocks']));
                $level = ['year_level_id' => $existing?->id, 'name' => $existing?->name ?? $newLevels[$ordinal], 'level' => $ordinal, 'blocks' => $names];
                foreach ($names as $row) {
                    if ($row['name'] === '') {
                        throw ValidationException::withMessages([$field.'.blocks' => 'Every block needs a name.']);
                    }
                    $key = $group['program_id'].':'.$ordinal.':'.Str::lower($row['name']);
                    if (isset($keys[$key])) {
                        throw ValidationException::withMessages([$field.'.blocks' => 'Block names must be unique within the same program and year level.']);
                    }
                    $keys[$key] = true;
                    $blocks[] = ['name' => $row['name'], 'program_id' => $group['program_id'], 'year_level_id' => $level['year_level_id'],
                        'new_name' => $existing ? null : $level['name'], 'new_level' => $existing ? null : $ordinal, 'group' => $groupIndex, 'year_level_group' => $levelIndex];
                }
            }
            unset($level);
            unset($group['expected_level_count']);
        }
        unset($group);
        if (count($blocks) > 30) {
            throw ValidationException::withMessages(['blocks' => 'Create at most 30 blocks across all programs and year levels per setup.']);
        }

        return ['structures' => $groups, 'blocks' => $blocks];
    }

    private static function checkStructureCounts(array $input): void
    {
        $hasCounts = array_key_exists('expected_structure_count', $input);
        foreach ($input['structures'] ?? [] as $group) {
            $hasCounts = $hasCounts || array_key_exists('expected_level_count', $group);
            foreach ($group['year_levels'] ?? [] as $level) {
                $hasCounts = $hasCounts || array_key_exists('expected_block_count', $level);
            }
        }
        if (! $hasCounts) {
            return;
        }
        $counts = Validator::make($input, ['expected_structure_count' => 'required|integer|min:1|max:20',
            'structures.*.expected_level_count' => 'required|integer|min:1|max:12', 'structures.*.year_levels.*.expected_block_count' => 'required|integer|min:1|max:30'])->validate();
        $complete = (int) $counts['expected_structure_count'] === count($input['structures']);
        foreach ($input['structures'] as $group) {
            $complete = $complete && (int) $group['expected_level_count'] === count($group['year_levels']);
            foreach ($group['year_levels'] as $level) {
                $complete = $complete && (int) $level['expected_block_count'] === count($level['blocks']);
            }
        }
        if (! $complete) {
            throw ValidationException::withMessages(['structures' => 'Not all program, year level or block rows reached the server. Nothing was created. Reload the form and check the server form-input limit.']);
        }
    }

    public static function blockChoices(array $draft): array
    {
        $blocks = $draft['blocks'] ?? [];
        $programIds = array_map(fn ($row) => $row['program_id'] ?? $draft['program_id'] ?? null, $blocks);
        $levelIds = array_map(fn ($row) => $row['year_level_id'] ?? $draft['year_level_id'] ?? null, $blocks);
        $programs = Program::whereIn('id', array_filter($programIds))->get(['id', 'code', 'name'])->keyBy('id');
        $levels = YearLevel::whereIn('id', array_filter($levelIds))->get(['id', 'name', 'level'])->keyBy('id');
        $choices = [];
        foreach ($blocks as $index => $block) {
            $program = $programs->get($programIds[$index]);
            // Explicit new levels must not fall back to a legacy top-level selection.
            $level = ! empty($block['new_level']) ? null : $levels->get($levelIds[$index]);
            $levelName = $level?->name ?? $block['new_name'] ?? null;
            $ordinal = $level?->level ?? $block['new_level'] ?? null;
            if (! $program || ! $levelName || ! $ordinal) {
                throw ValidationException::withMessages(['blocks' => 'Choose a program and year level for every block before continuing.']);
            }
            $choices[$index] = ['index' => $index, 'name' => $block['name'], 'program' => $program->code, 'program_name' => $program->name,
                'year_level' => $levelName, 'level' => (int) $ordinal, 'label' => $program->code.' — '.$levelName.' — Block '.$block['name']];
        }

        return $choices;
    }

    public static function validateDraft(array $draft): array
    {
        $data = [];
        for ($step = 1; $step <= 4; $step++) {
            $data = array_merge($data, self::normalizeStep($step, array_merge($draft, $data)));
        }
        $pairs = [];
        $teachers = Teacher::whereIn('id', array_column($data['assignments'], 'teacher_id'))->with('user')->get()->keyBy('id');
        $curriculum = Subject::with('yearLevel')->whereIn('id', array_filter(array_column($data['subjects'], 'id')))->get()->keyBy('id');
        foreach ($data['assignments'] as $index => $row) {
            $teacherUser = $teachers->get($row['teacher_id'])?->user;
            if (! isset($data['subjects'][$row['subject']]) || ! $teacherUser || $teacherUser->role !== 'teacher') {
                throw ValidationException::withMessages(["assignments.$index.teacher_id" => 'Choose an existing teacher account and a subject from this setup.']);
            }
            foreach ($row['blocks'] as $blockIndex) {
                if (! isset($data['blocks'][$blockIndex])) {
                    throw ValidationException::withMessages(["assignments.$index.blocks" => 'Select only blocks included in this setup.']);
                }
                $subject = $curriculum->get($data['subjects'][$row['subject']]['id']);
                $block = $data['blocks'][$blockIndex];
                if ($subject?->program_id && ($subject->program_id != $block['program_id'] || $subject->semester != $data['semester'] || ($block['year_level_id'] ? $subject->year_level_id != $block['year_level_id'] : $subject->yearLevel->level != $block['new_level']))) {
                    throw ValidationException::withMessages(["assignments.$index.blocks" => 'The curriculum subject must match the block Program, Year Level and Semester.']);
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
        $blockChoices = self::blockChoices($data);
        $csv = empty($draft['csv_rows']) ? ['rows' => [], 'errors' => []] : StudentCsvImport::validateRows($draft['csv_rows'], $blockChoices);
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
        foreach ($csv['rows'] as $row) {
            $enrollmentCount += $classCounts[$row['block_index']] ?? 0;
        }
        if ($enrollmentCount > 10000) {
            throw ValidationException::withMessages(['students' => 'This setup would create more than 10,000 enrollments. Split enrollment into smaller batches.']);
        }

        return $data;
    }

    public static function duplicate(array $scope, bool $copyStudents, bool $copySchedules): array
    {
        $scope = Validator::make($scope, ['academic_year_id' => 'required|integer|exists:academic_years,id', 'program_id' => 'required|integer|exists:programs,id', 'year_level_id' => 'required|integer|exists:year_levels,id', 'semester' => 'required|integer|in:1,2,3'])->validate();
        $blocks = Block::where($scope)->with(['classes.subject.program', 'classes.subject.yearLevel', 'classes.teacher.user'])->orderBy('id')->limit(31)->get();
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
        $level = YearLevel::findOrFail($scope['year_level_id']);

        return [
            'program_id' => (int) $scope['program_id'], 'year_level_id' => (int) $scope['year_level_id'],
            'structures' => [['program_id' => (int) $scope['program_id'], 'year_levels' => [['year_level_id' => (int) $scope['year_level_id'],
                'name' => $level->name, 'level' => $level->level,
                'blocks' => $blocks->map(fn ($block) => ['name' => $block->name])->all()]]]],
            'blocks' => $blocks->map(fn ($block) => ['name' => $block->name, 'program_id' => $block->program_id, 'year_level_id' => $block->year_level_id,
                'new_name' => null, 'new_level' => null, 'group' => 0, 'year_level_group' => 0])->all(),
            'subjects' => $subjects->map(fn ($subject) => ['id' => $subject->id, 'code' => $subject->code, 'name' => $subject->name, 'units' => $subject->units, 'label' => $subject->catalog_label])->all(),
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
            self::resolveBlockLevels($data);
            $blockQuery = Block::where('academic_year_id', $year->id)->where('semester', $data['semester'])->where(function ($query) use ($data) {
                foreach ($data['blocks'] as $row) {
                    $query->orWhere(fn ($scope) => $scope->where('program_id', $row['program_id'])->where('year_level_id', $row['year_level_id'])->whereRaw('LOWER(name) = ?', [Str::lower($row['name'])]));
                }
            });
            if ((clone $blockQuery)->exists()) {
                throw ValidationException::withMessages(['blocks' => 'One or more target blocks already exist for this academic year, semester, program and year level. Change the names or correct them on the management pages.']);
            }
            // These catalog models have no creation hooks. Batch writes avoid one
            // database round trip for every block, subject and assigned class.
            $now = now();
            $blockScope = ['academic_year_id' => $year->id, 'semester' => $data['semester']];
            Block::insert(array_map(fn ($row) => $blockScope + ['program_id' => $row['program_id'], 'year_level_id' => $row['year_level_id'], 'name' => $row['name'], 'created_at' => $now, 'updated_at' => $now], $data['blocks']));
            $blockMap = $blockQuery->get()->keyBy(fn ($block) => self::blockKey($block->program_id, $block->year_level_id, $block->name));
            $blocks = array_map(fn ($row) => $blockMap->get(self::blockKey($row['program_id'], $row['year_level_id'], $row['name'])), $data['blocks']);
            $newSubjects = array_filter($data['subjects'], fn ($row) => ! $row['id']);
            if ($newSubjects) {
                Subject::insert(array_map(fn ($row) => ['code' => $row['code'], 'name' => $row['name'], 'units' => $row['units'], 'status' => 'active', 'created_at' => $now, 'updated_at' => $now], array_values($newSubjects)));
            }
            $catalog = Subject::whereIn('id', array_filter(array_column($data['subjects'], 'id')))->orWhere(function ($query) use ($newSubjects) {
                $query->whereNull('program_id')->whereIn('code', array_column($newSubjects, 'code'));
            })->get();
            $subjects = array_map(fn ($row) => $row['id'] ? $catalog->firstWhere('id', $row['id']) : $catalog->first(fn ($subject) => ! $subject->program_id && $subject->code === $row['code']), $data['subjects']);
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

    private static function blockKey(int $programId, int $levelId, string $name): string
    {
        return $programId.':'.$levelId.':'.Str::lower($name);
    }

    private static function resolveBlockLevels(array &$data): void
    {
        $new = [];
        foreach ($data['blocks'] as $block) {
            if (! $block['year_level_id']) {
                $new[(int) $block['new_level']] = $block['new_name'];
            }
        }
        if (! $new) {
            return;
        }
        $existing = YearLevel::whereIn('level', array_keys($new))->lockForUpdate()->get()->keyBy('level');
        $missing = array_diff_key($new, $existing->all());
        if ($missing) {
            if (YearLevel::whereIn(DB::raw('LOWER(name)'), array_map(fn ($name) => Str::lower($name), array_values($missing)))->exists()) {
                throw ValidationException::withMessages(['structures' => 'A new year level name now belongs to another level. Review the available year levels and try again.']);
            }
            $now = now();
            YearLevel::insert(array_map(fn ($ordinal, $name) => ['level' => $ordinal, 'name' => $name, 'created_at' => $now, 'updated_at' => $now], array_keys($missing), array_values($missing)));
            $existing = YearLevel::whereIn('level', array_keys($new))->get()->keyBy('level');
        }
        foreach ($data['blocks'] as &$block) {
            if (! $block['year_level_id']) {
                $block['year_level_id'] = $existing[$block['new_level']]->id;
            }
        }
        unset($block);
    }

    private static function copySchedules(array $data, array $draft, array $blocks, array $classes): int
    {
        if (! $data['copy_schedules'] || ! $data['source']) {
            return 0;
        }
        // Re-query the source scope; client fields and stale source IDs cannot select other records.
        $sourceRows = Block::where($data['source'])->orderBy('id')->limit(30)->get();
        $sourceBlocks = $sourceRows->pluck('id')->all();
        $sourceIds = $draft['source_block_ids'] ?? $sourceBlocks;
        if (! is_array($sourceIds) || count($sourceIds) !== count($blocks) || array_map('intval', $sourceIds) !== $sourceBlocks) {
            throw ValidationException::withMessages(['schedules' => 'Copied blocks changed. Load the previous setup again or turn off Copy Schedules before saving.']);
        }
        foreach ($blocks as $index => $block) {
            if ((int) $block->program_id !== (int) $sourceRows[$index]->program_id || (int) $block->year_level_id !== (int) $sourceRows[$index]->year_level_id) {
                throw ValidationException::withMessages(['schedules' => 'The copied program or year level changed. Turn off Copy Schedules or reload the previous setup before saving.']);
            }
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
            $selection[] = ['student_id' => $row['existing_student_id'], 'user_id' => $user->id, 'student_number' => $row['student_number'], 'block' => $row['block_index']];
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
