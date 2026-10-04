<?php

namespace App\Services;

use App\Models\Program;
use App\Models\Subject;
use App\Models\YearLevel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class CurriculumImport
{
    private const HEADERS = ['program_code', 'year_level', 'semester', 'course_code', 'course_title', 'units', 'lecture_units', 'laboratory_units', 'prerequisite'];

    public function read(string $path): array
    {
        if (! is_file($path) || ! is_readable($path) || filesize($path) > 5 * 1024 * 1024) {
            throw new RuntimeException('Curriculum CSV must be a readable file no larger than 5 MB.');
        }
        $file = fopen($path, 'rb');
        try {
            $headers = fgetcsv($file, 0, ',', '"', '');
            if ($headers) {
                $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', $headers[0]);
            }
            if ($headers !== self::HEADERS) {
                throw new RuntimeException('CSV headers must exactly match: '.implode(',', self::HEADERS));
            }
            $rows = [];
            $keys = [];
            $line = 1;
            while (($values = fgetcsv($file, 0, ',', '"', '')) !== false) {
                $line++;
                if ($values === [null]) {
                    continue;
                }
                if (count($values) !== count(self::HEADERS)) {
                    throw new RuntimeException("CSV row $line has an incorrect number of fields.");
                }
                $row = array_combine(self::HEADERS, $values);
                foreach ($row as $key => $value) {
                    if (! mb_check_encoding($value, 'UTF-8') || trim($value) !== $value) {
                        throw new RuntimeException("CSV row $line has invalid encoding or surrounding whitespace in $key.");
                    }
                }
                if (! isset(config('curriculum.programs')[$row['program_code']])) {
                    throw new RuntimeException("CSV row $line has an unknown program code: {$row['program_code']}.");
                }
                if (! preg_match('/^([1-9]|1[0-2]) Year$/', $row['year_level'], $match)) {
                    throw new RuntimeException("CSV row $line needs a year level such as 1 Year.");
                }
                $row['level'] = (int) $match[1];
                $row['semester_number'] = match ($row['semester']) {
                    '1st' => 1, '2nd' => 2, 'Summer', '3rd' => 3,
                    default => throw new RuntimeException("CSV row $line has an unknown semester."),
                };
                foreach (['course_code', 'course_title'] as $field) {
                    if ($row[$field] === '' || mb_strlen($row[$field]) > 120 || preg_match('/[\x00-\x1F]/', $row[$field])) {
                        throw new RuntimeException("CSV row $line has an invalid $field.");
                    }
                }
                foreach (['units', 'lecture_units', 'laboratory_units'] as $field) {
                    if (! preg_match('/^\d{1,3}(?:\.\d{1,2})?$/', $row[$field]) || (float) $row[$field] > 12) {
                        throw new RuntimeException("CSV row $line has invalid $field; use 0–12 with at most two decimals.");
                    }
                    $row[$field] = number_format((float) $row[$field], 2, '.', '');
                }
                if ((float) $row['units'] <= 0 || abs((float) $row['units'] - (float) $row['lecture_units'] - (float) $row['laboratory_units']) > 0.0001) {
                    throw new RuntimeException("CSV row $line total units must equal lecture plus laboratory units.");
                }
                $key = strtolower($row['program_code'].'|'.$row['level'].'|'.$row['semester_number'].'|'.$row['course_code']);
                if (isset($keys[$key])) {
                    throw new RuntimeException("CSV row $line duplicates a program/year/semester/course code.");
                }
                $keys[$key] = true;
                $row['prerequisite_codes'] = $row['prerequisite'] === '' ? [] : array_map('trim', explode(';', $row['prerequisite']));
                if (count(array_unique(array_map('strtolower', $row['prerequisite_codes']))) !== count($row['prerequisite_codes'])) {
                    throw new RuntimeException("CSV row $line repeats a prerequisite.");
                }
                $rows[] = $row;
                if (count($rows) > 10000) {
                    throw new RuntimeException('Curriculum supports at most 10,000 rows per import.');
                }
            }
            if (! $rows) {
                throw new RuntimeException('The curriculum CSV is empty.');
            }
            $this->resolve($rows);

            return $rows;
        } finally {
            fclose($file);
        }
    }

    private function resolve(array $rows): array
    {
        $edges = [];
        foreach ($rows as $index => $row) {
            foreach ($row['prerequisite_codes'] as $code) {
                $matches = array_keys(array_filter($rows, fn ($candidate) => $candidate['program_code'] === $row['program_code'] && $candidate['course_code'] === $code));
                if (count($matches) !== 1) {
                    throw new RuntimeException("{$row['program_code']} / {$row['course_code']}: prerequisite $code must resolve to exactly one course in the same program.");
                }
                $target = $matches[0];
                if ($target === $index) {
                    throw new RuntimeException("{$row['program_code']} / {$row['course_code']}: a course cannot require itself.");
                }
                $edges[] = [$index, $target];
            }
        }
        $graph = [];
        foreach ($edges as [$source, $target]) {
            $graph[$source][] = $target;
        }
        $visiting = [];
        $visited = [];
        $visit = function ($node) use (&$visit, &$visiting, &$visited, $graph): void {
            if (isset($visiting[$node])) {
                throw new RuntimeException('The CSV contains a prerequisite cycle. No records changed.');
            }
            if (isset($visited[$node])) {
                return;
            }
            $visiting[$node] = true;
            foreach ($graph[$node] ?? [] as $target) {
                $visit($target);
            }
            unset($visiting[$node]);
            $visited[$node] = true;
        };
        foreach (array_keys($rows) as $node) {
            $visit($node);
        }

        return $edges;
    }

    public function summary(array $rows): array
    {
        return ['programs' => count(array_unique(array_column($rows, 'program_code'))), 'year_levels' => count(array_unique(array_column($rows, 'level'))), 'semesters' => count(array_unique(array_column($rows, 'semester_number'))), 'courses' => count($rows), 'prerequisites' => count($this->resolve($rows))];
    }

    public function run(array $rows, bool $dryRun = false): array
    {
        $edges = $this->resolve($rows);

        return DB::transaction(function () use ($rows, $edges, $dryRun) {
            // The existing program rows serialize concurrent curriculum imports.
            $programs = Program::orderBy('id')->lockForUpdate()->get();
            $levels = YearLevel::orderBy('level')->lockForUpdate()->get()->keyBy('level');
            $subjects = Subject::with('classAssignments.block')->orderBy('id')->lockForUpdate()->get();
            $result = $this->summary($rows) + ['programs_created' => 0, 'year_levels_created' => 0, 'courses_created' => 0, 'courses_updated' => 0, 'courses_unchanged' => 0, 'prerequisites_created' => 0];
            $programIds = [];
            foreach (array_unique(array_column($rows, 'program_code')) as $code) {
                $matches = $programs->filter(fn ($p) => Str::lower($p->code) === Str::lower($code));
                if ($matches->count() > 1) {
                    throw new RuntimeException("Ambiguous existing program $code; no records changed.");
                }
                $program = $matches->first();
                if (! $program) {
                    $result['programs_created']++;
                    $program = $dryRun ? new Program(['code' => $code]) : Program::create(['code' => $code, 'name' => config('curriculum.programs')[$code]]);
                }
                $programIds[$code] = $program->id;
            }
            $levelIds = [];
            foreach (array_unique(array_column($rows, 'level')) as $level) {
                $record = $levels->get($level);
                if (! $record) {
                    $name = $level.' Year';
                    if (YearLevel::where('name', $name)->exists()) {
                        throw new RuntimeException("Year level name $name already belongs to a different level; no records changed.");
                    }
                    $result['year_levels_created']++;
                    $record = $dryRun ? new YearLevel(['level' => $level]) : YearLevel::create(['level' => $level, 'name' => $name]);
                }
                $levelIds[$level] = $record->id;
            }
            $saved = [];
            foreach ($rows as $index => $row) {
                $programId = $programIds[$row['program_code']];
                $levelId = $levelIds[$row['level']];
                $matches = $subjects->filter(fn ($s) => $programId && $levelId && $s->program_id == $programId && $s->year_level_id == $levelId && $s->semester == $row['semester_number'] && Str::lower($s->code) === Str::lower($row['course_code']));
                if ($matches->count() > 1) {
                    throw new RuntimeException("Duplicate existing curriculum course {$row['course_code']}; no records changed.");
                }
                $subject = $matches->first();
                // Adopt a legacy course only when its data match exactly and every
                // existing class already belongs to this exact curriculum scope.
                if (! $subject) {
                    $legacy = $subjects->filter(fn ($s) => $s->program_id === null && $s->code === $row['course_code'] && $s->name === $row['course_title'] && (float) $s->units === (float) $row['units'] && $s->classAssignments->isNotEmpty() && $s->classAssignments->every(fn ($c) => $c->block->program_id == $programId && $c->block->year_level_id == $levelId && $c->block->semester == $row['semester_number']));
                    if ($legacy->count() > 1) {
                        throw new RuntimeException("Ambiguous legacy course {$row['course_code']}; no records changed.");
                    }
                    $subject = $legacy->first();
                }
                $data = ['program_id' => $programId, 'year_level_id' => $levelId, 'semester' => $row['semester_number'], 'code' => $row['course_code'], 'name' => $row['course_title'], 'units' => $row['units'], 'lecture_units' => $row['lecture_units'], 'laboratory_units' => $row['laboratory_units']];
                if ($subject) {
                    $subject->fill($data);
                    $result[$subject->isDirty() ? 'courses_updated' : 'courses_unchanged']++;
                    if (! $dryRun && $subject->isDirty()) {
                        $subject->save();
                    }
                } else {
                    $result['courses_created']++;
                    $subject = new Subject($data + ['status' => 'active']);
                }
                $saved[$index] = $subject;
            }
            if (! $dryRun) {
                $newSubjects = array_filter($saved, fn ($subject) => ! $subject->exists);
                foreach (array_chunk($newSubjects, 500) as $chunk) {
                    Subject::insert(array_map(fn ($subject) => $subject->getAttributes() + ['created_at' => now(), 'updated_at' => now()], $chunk));
                }
                if ($newSubjects) {
                    $key = fn ($subject) => $subject->program_id.'|'.$subject->year_level_id.'|'.$subject->semester.'|'.$subject->code;
                    $inserted = Subject::whereNotNull('program_id')->whereIn('code', array_map(fn ($subject) => $subject->code, $newSubjects))->get()->keyBy($key);
                    foreach ($newSubjects as $index => $subject) {
                        $saved[$index] = $inserted->get($key($subject));
                    }
                }
            }
            $existingLinks = DB::table('subject_prerequisites')->whereIn('subject_id', array_filter(array_map(fn ($subject) => $subject->id, $saved)))->get();
            $linkKeys = $existingLinks->mapWithKeys(fn ($link) => [$link->subject_id.':'.$link->prerequisite_id => true])->all();
            foreach ($saved as $index => $subject) {
                if ($subject->exists) {
                    $expected = array_map(fn ($edge) => $saved[$edge[1]]->id, array_filter($edges, fn ($edge) => $edge[0] === $index));
                    foreach ($existingLinks->where('subject_id', $subject->id)->pluck('prerequisite_id') as $prerequisiteId) {
                        if (! in_array($prerequisiteId, $expected, true)) {
                            throw new RuntimeException("Existing prerequisites differ for {$subject->code}; review required. No links were deleted.");
                        }
                    }
                }
            }
            $newLinks = [];
            foreach ($edges as [$course, $prerequisite]) {
                if (! $saved[$course]->exists || ! $saved[$prerequisite]->exists || ! isset($linkKeys[$saved[$course]->id.':'.$saved[$prerequisite]->id])) {
                    $result['prerequisites_created']++;
                    if (! $dryRun) {
                        $newLinks[] = ['subject_id' => $saved[$course]->id, 'prerequisite_id' => $saved[$prerequisite]->id];
                    }
                }
            }
            // Never silently retain prerequisite relationships absent from the source.
            if (! $dryRun) {
                foreach (array_chunk($newLinks, 500) as $chunk) {
                    DB::table('subject_prerequisites')->insert($chunk);
                }
                $links = DB::table('subject_prerequisites')->whereIn('subject_id', array_map(fn ($subject) => $subject->id, $saved))->get();
                foreach ($saved as $index => $subject) {
                    $expected = array_map(fn ($edge) => $saved[$edge[1]]->id, array_filter($edges, fn ($edge) => $edge[0] === $index));
                    $actual = $links->where('subject_id', $subject->id)->pluck('prerequisite_id')->all();
                    sort($expected);
                    sort($actual);
                    if ($actual !== $expected) {
                        throw new RuntimeException("Existing prerequisites differ for {$subject->code}; review required. Import rolled back without deleting any links.");
                    }
                }
            }

            return $result;
        }, 3);
    }
}
