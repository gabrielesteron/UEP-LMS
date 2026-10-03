<?php

namespace App\Services;

use App\Models\Student;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class StudentCsvImport
{
    public const MAX_ROWS = 500;

    public const MAX_BYTES = 1048576;

    public const MAX_BLOCKS = 300;

    /** Preview only: never persist accounts, files, or invitations. */
    public static function preview(UploadedFile $file, array $blockNames): array
    {
        if (! $file->isValid() || $file->getSize() > self::MAX_BYTES) {
            return self::result([], [0 => ['Choose a valid CSV file no larger than 1 MB.']]);
        }
        if (strtolower($file->getClientOriginalExtension()) !== 'csv') {
            return self::result([], [0 => ['Choose a CSV file with a .csv extension.']]);
        }

        $content = file_get_contents($file->getRealPath());
        if ($content !== false && strlen($content) > self::MAX_BYTES) {
            return self::result([], [0 => ['Choose a CSV file no larger than 1 MB.']]);
        }
        if ($content === false || $content === '' || ! mb_check_encoding($content, 'UTF-8') || str_contains($content, "\0")) {
            return self::result([], [0 => ['The CSV must contain readable UTF-8 text and a header row.']]);
        }
        if (! self::wellFormedCsv($content)) {
            return self::result([], [0 => ['The CSV contains an unclosed or misplaced quotation mark. Export it as a comma-separated CSV and try again.']]);
        }

        $stream = fopen($file->getRealPath(), 'rb');
        if ($stream === false) {
            return self::result([], [0 => ['The CSV could not be read. Upload it again.']]);
        }

        try {
            if (str_starts_with($content, "\xEF\xBB\xBF")) {
                fseek($stream, 3);
            }
            $header = fgetcsv($stream, 0, ',', '"', '');
            $headers = array_map(fn ($value) => preg_replace('/[\s-]+/u', '_', mb_strtolower(trim((string) $value))), $header ?: []);
            if ($headers) {
                $headers[0] = preg_replace('/^\x{FEFF}/u', '', $headers[0]);
            }
            $required = ['student_id', 'name', 'email', 'block'];
            if (array_diff($required, $headers)) {
                return self::result([], [0 => ['The CSV header must include Student ID, Name, Email, and Block.']]);
            }
            if (in_array('', $headers, true) || count($headers) !== count(array_unique($headers))) {
                return self::result([], [0 => ['Every CSV column must have a unique, non-empty header.']]);
            }

            $rows = [];
            $errors = [];
            $rowNumber = 1;
            while (($values = fgetcsv($stream, 0, ',', '"', '')) !== false) {
                $rowNumber++;
                if (count($values) === 1 && trim((string) $values[0]) === '') {
                    continue;
                }
                if (count($rows) === self::MAX_ROWS) {
                    $errors[0][] = 'A CSV may contain at most 500 student rows. Split the file into smaller imports.';
                    break;
                }
                $data = count($values) === count($headers) ? array_combine($headers, $values) : [];
                $rows[] = [
                    'row' => $rowNumber,
                    'student_number' => $data['student_id'] ?? '',
                    'name' => $data['name'] ?? '',
                    'email' => $data['email'] ?? '',
                    'program' => $data['program'] ?? '',
                    'year_level' => $data['year_level'] ?? '',
                    'block' => $data['block'] ?? '',
                ];
                if (! $data) {
                    $errors[$rowNumber][] = 'The number of values does not match the CSV header.';
                }
            }
            if (! $rows) {
                $errors[0][] = 'The CSV must contain at least one student row.';
            }

            return self::validate($rows, $blockNames, $errors);
        } finally {
            fclose($stream);
        }
    }

    /** Recheck posted preview data and resolve identities again from the database. */
    public static function validateRows(array $rows, array $blockNames): array
    {
        $errors = [];
        if (! $rows) {
            $errors[0][] = 'Add at least one student row before importing.';
        }
        if (count($rows) > self::MAX_ROWS) {
            $errors[0][] = 'An import may contain at most 500 student rows.';
            $rows = array_slice($rows, 0, self::MAX_ROWS);
        }
        $bytes = 0;
        foreach ($rows as $row) {
            foreach (['student_number', 'name', 'email', 'program', 'year_level', 'block'] as $field) {
                $bytes += is_array($row) && is_string($row[$field] ?? null) ? strlen($row[$field]) : 0;
            }
            if ($bytes > self::MAX_BYTES) {
                return self::result([], [0 => ['Student import data must not exceed 1 MB.']]);
            }
        }

        return self::validate($rows, $blockNames, $errors);
    }

    public static function template(bool $multiplePrograms = false): string
    {
        if ($multiplePrograms) {
            return "student_id,name,email,program,year_level,block\r\n2026-0001,Example Student,student@example.com,BSIT,2,2A\r\n";
        }

        return "student_id,name,email,block\r\n2026-0001,Example Student,student@example.com,Block A\r\n";
    }

    private static function validate(array $input, array $blockNames, array $errors): array
    {
        $blocks = self::targetBlocks($blockNames, $errors);

        $rows = [];
        $identifiers = ['student_number' => [], 'email' => []];
        foreach (array_values($input) as $index => $data) {
            $data = is_array($data) ? $data : [];
            // Do not trust posted row numbers or existing account IDs.
            $number = isset($data['row']) && filter_var($data['row'], FILTER_VALIDATE_INT) !== false && (int) $data['row'] >= 2
                ? (int) $data['row'] : $index + 2;
            if (isset($rows[$number])) {
                $errors[0][] = 'The preview contains duplicate row numbers. Upload the CSV again.';
                $number = max(array_keys($rows)) + 1;
            }
            $row = ['row' => $number];
            foreach (['student_number', 'name', 'email', 'program', 'year_level', 'block'] as $field) {
                $row[$field] = is_string($data[$field] ?? null) ? trim($data[$field]) : '';
                if (in_array($field, ['program', 'year_level'], true) && isset($data[$field]) && ! is_string($data[$field])) {
                    $errors[$number][] = ($field === 'program' ? 'Program' : 'Year Level').' must be a text value.';
                }
            }
            $row['block_index'] = null;
            $row['block_label'] = '';
            $row['email'] = self::key($row['email']);
            $row['existing_student_id'] = null;
            $row['existing_user_id'] = null;
            $row['action'] = 'new';

            $validator = Validator::make($row, [
                'student_number' => ['required', 'string', 'max:120', 'not_regex:/[\x00-\x1F\x7F]/u'],
                'name' => ['required', 'string', 'max:120', 'not_regex:/[\x00-\x1F\x7F]/u'],
                'email' => ['required', 'email:rfc', 'max:255'],
                'program' => ['nullable', 'string', 'max:120'],
                'year_level' => ['nullable', 'string', 'max:120'],
                'block' => ['required', 'string', 'max:120'],
            ], [], ['student_number' => 'Student ID', 'name' => 'Name', 'email' => 'Email', 'program' => 'Program', 'year_level' => 'Year Level', 'block' => 'Block']);
            if ($validator->fails()) {
                $errors[$number] = array_merge($errors[$number] ?? [], $validator->errors()->all());
            }
            self::resolveBlock($row, $blocks, $errors);
            foreach (['student_number', 'email'] as $field) {
                $key = self::key($row[$field]);
                if ($key !== '') {
                    if (isset($identifiers[$field][$key])) {
                        $otherRow = $identifiers[$field][$key];
                        $label = $field === 'email' ? 'Email' : 'Student ID';
                        $errors[$number][] = $label.' is duplicated in CSV row '.$otherRow.'.';
                        $errors[$otherRow][] = $label.' is duplicated in CSV row '.$number.'.';
                    } else {
                        $identifiers[$field][$key] = $number;
                    }
                }
            }
            $rows[$number] = $row;
        }

        // Lowercased bulk lookups also catch collisions on SQLite's case-sensitive uniques.
        $users = User::withTrashed()->whereIn(DB::raw('LOWER(email)'), array_keys($identifiers['email']))->get();
        $usersByEmail = $users->groupBy(fn ($user) => self::key($user->email));
        $students = Student::with(['user' => fn ($query) => $query->withTrashed()])
            ->where(function ($query) use ($identifiers, $users) {
                $query->whereIn(DB::raw('LOWER(student_number)'), array_keys($identifiers['student_number']))
                    ->orWhereIn('user_id', $users->pluck('id'));
            })->get();
        $studentsByNumber = $students->groupBy(fn ($student) => self::key($student->student_number));
        $studentsByUser = $students->keyBy('user_id');

        foreach ($rows as $number => &$row) {
            $matchingUsers = $usersByEmail->get(self::key($row['email']), collect());
            $matchingStudents = $studentsByNumber->get(self::key($row['student_number']), collect());
            $user = $matchingUsers->first();
            $student = $matchingStudents->first();
            if ($matchingUsers->count() > 1 || $matchingStudents->count() > 1) {
                $errors[$number][] = 'Existing records have conflicting Student IDs or email addresses. Resolve them before importing.';
            }
            if ($user && ($user->trashed() || $user->role !== 'student')) {
                $errors[$number][] = $user->trashed() ? 'Email belongs to an archived account.' : 'Email belongs to an account with a different role.';
            }
            if ($student) {
                if (! $student->user || $student->user->trashed() || $student->user->role !== 'student') {
                    $errors[$number][] = 'Student ID belongs to an archived or incompatible account.';
                } elseif (self::key($student->user->email) !== self::key($row['email'])) {
                    $errors[$number][] = 'Student ID already belongs to a different email address.';
                } elseif (! $user || $student->user_id !== $user->id) {
                    $errors[$number][] = 'Student ID and email do not identify the same account.';
                } else {
                    $row['existing_student_id'] = $student->id;
                    $row['existing_user_id'] = $user->id;
                    $row['action'] = 'existing';
                }
            } elseif ($user && isset($studentsByUser[$user->id])) {
                $errors[$number][] = 'Email already belongs to a student with a different Student ID.';
            } elseif ($user && ! $user->trashed() && $user->role === 'student') {
                $row['existing_user_id'] = $user->id;
                $row['action'] = 'existing';
            }
        }
        unset($row);

        return self::result(array_values($rows), $errors);
    }

    /** Both legacy names and scoped descriptors are trusted setup targets, never CSV data. */
    private static function targetBlocks(array $targets, array &$errors): array
    {
        if (count($targets) > self::MAX_BLOCKS) {
            $errors[0][] = 'Configure at most 300 target blocks for one student import.';
            $targets = array_slice($targets, 0, self::MAX_BLOCKS, true);
        }
        $blocks = [];
        $scopes = [];
        $indices = [];
        $legacy = count(array_filter($targets, 'is_string')) === count($targets);
        $descriptors = count(array_filter($targets, 'is_array')) === count($targets);
        if (! $legacy && ! $descriptors) {
            $errors[0][] = 'Target blocks must use one consistent setup format.';

            return [];
        }
        foreach ($targets as $key => $target) {
            $index = $legacy ? (is_int($key) ? $key : count($blocks)) : ($target['index'] ?? $key);
            $name = $legacy ? trim($target) : (is_string($target['name'] ?? null) ? trim($target['name']) : '');
            if (filter_var($index, FILTER_VALIDATE_INT) === false || (int) $index < 0 || $name === '' || mb_strlen($name) > 120) {
                $errors[0][] = 'Configure a valid index and a non-empty block name of at most 120 characters for each target block.';

                continue;
            }
            $index = (int) $index;
            $program = $legacy ? '' : (is_string($target['program'] ?? null) ? trim($target['program']) : '');
            $programName = $legacy ? '' : (is_string($target['program_name'] ?? null) ? trim($target['program_name']) : '');
            $yearLevel = $legacy ? '' : (is_string($target['year_level'] ?? null) ? trim($target['year_level']) : '');
            $level = $legacy ? null : ($target['level'] ?? null);
            if (! $legacy && ($program === '' || $programName === '' || $yearLevel === '' || max(mb_strlen($program), mb_strlen($programName), mb_strlen($yearLevel)) > 120 || filter_var($level, FILTER_VALIDATE_INT) === false || (int) $level < 1 || (int) $level > 12)) {
                $errors[0][] = 'Each target block needs a valid Program and Year Level from this setup.';

                continue;
            }
            $scope = json_encode([self::key($program), $level === null ? null : (int) $level, self::key($name)]);
            if (isset($scopes[$scope])) {
                $errors[0][] = $legacy ? 'Target block names must be unique, ignoring letter case.' : 'Each target block must have a unique Program, Year Level, and Block name.';
            }
            if (isset($indices[$index])) {
                $errors[0][] = 'Target blocks must have unique setup indices.';
            }
            $scopes[$scope] = true;
            $indices[$index] = true;
            $label = $legacy ? $name : $program.' — '.$yearLevel.' — Block '.$name;
            if (! $legacy && is_string($target['label'] ?? null) && trim($target['label']) !== '') {
                $label = mb_substr(trim($target['label']), 0, 400);
            }
            $blocks[] = ['index' => $index, 'name' => $name, 'program' => $program, 'program_name' => $programName, 'year_level' => $yearLevel, 'level' => $level === null ? null : (int) $level, 'label' => $label];
        }
        if (! $blocks) {
            $errors[0][] = 'Configure at least one target block before importing students.';
        }

        return $blocks;
    }

    private static function resolveBlock(array &$row, array $blocks, array &$errors): void
    {
        if ($row['block'] === '') {
            return;
        }
        $byName = array_values(array_filter($blocks, fn ($block) => self::key($block['name']) === self::key($row['block'])));
        if (! $byName) {
            $errors[$row['row']][] = 'Block must match one of the blocks in this academic setup.';

            return;
        }
        $matches = array_values(array_filter($byName, function ($block) use ($row) {
            $program = self::key($row['program']);
            $yearLevel = self::key($row['year_level']);
            $programMatches = $program === '' || $program === self::key($block['program']) || $program === self::key($block['program_name']);
            $levelMatches = $yearLevel === '' || $yearLevel === self::key($block['year_level']) || ($block['level'] !== null && ctype_digit($yearLevel) && (int) $yearLevel === $block['level']);

            return $programMatches && $levelMatches;
        }));
        if (count($matches) === 1) {
            $row['block'] = $matches[0]['name'];
            $row['block_index'] = $matches[0]['index'];
            $row['block_label'] = $matches[0]['label'];

            return;
        }
        $choices = implode('; ', array_column(array_slice($matches ?: $byName, 0, 5), 'label'));
        $more = count($matches ?: $byName) > 5 ? '; additional choices are listed in the setup' : '';
        $errors[$row['row']][] = $matches
            ? 'Block is ambiguous. Add Program and Year Level columns to identify the correct target. Choices: '.$choices.$more.'.'
            : 'Program or Year Level does not match this Block in the academic setup. Choices: '.$choices.$more.'.';
    }

    private static function result(array $rows, array $errors): array
    {
        foreach ($rows as &$row) {
            $row['errors'] = array_values(array_unique($errors[$row['row']] ?? []));
            $row['valid'] = ! $row['errors'];
        }
        unset($row);
        $errors = array_map(fn ($messages) => array_values(array_unique($messages)), $errors);
        $valid = count(array_filter($rows, fn ($row) => $row['valid']));

        return ['rows' => $rows, 'errors' => $errors, 'valid_count' => $valid, 'invalid_count' => count($rows) - $valid];
    }

    private static function key(string $value): string
    {
        return mb_strtolower($value);
    }

    private static function wellFormedCsv(string $content): bool
    {
        $content = preg_replace('/^\x{FEFF}/u', '', $content);
        $quoted = false;
        $afterQuote = false;
        $fieldStart = true;
        $length = strlen($content);
        for ($i = 0; $i < $length; $i++) {
            $char = $content[$i];
            if ($quoted) {
                if ($char === '"') {
                    if ($i + 1 < $length && $content[$i + 1] === '"') {
                        $i++;
                    } else {
                        $quoted = false;
                        $afterQuote = true;
                    }
                }

                continue;
            }
            if ($char === ',' || $char === "\r" || $char === "\n") {
                $afterQuote = false;
                $fieldStart = true;
            } elseif ($afterQuote) {
                return false;
            } elseif ($char === '"') {
                if (! $fieldStart) {
                    return false;
                }
                $quoted = true;
                $fieldStart = false;
            } else {
                $fieldStart = false;
            }
        }

        return ! $quoted;
    }
}
