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
            foreach (['student_number', 'name', 'email', 'block'] as $field) {
                $bytes += is_array($row) && is_string($row[$field] ?? null) ? strlen($row[$field]) : 0;
            }
            if ($bytes > self::MAX_BYTES) {
                return self::result([], [0 => ['Student import data must not exceed 1 MB.']]);
            }
        }

        return self::validate($rows, $blockNames, $errors);
    }

    public static function template(): string
    {
        return "student_id,name,email,block\r\n2026-0001,Example Student,student@example.com,Block A\r\n";
    }

    private static function validate(array $input, array $blockNames, array $errors): array
    {
        $blocks = [];
        foreach ($blockNames as $name) {
            if (! is_string($name) || trim($name) === '') {
                $errors[0][] = 'Configure a non-empty name for each target block first.';

                continue;
            }
            $key = self::key(trim($name));
            if (isset($blocks[$key])) {
                $errors[0][] = 'Target block names must be unique, ignoring letter case.';
            }
            $blocks[$key] = trim($name);
        }
        if (! $blocks) {
            $errors[0][] = 'Configure at least one target block before importing students.';
        }

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
            foreach (['student_number', 'name', 'email', 'block'] as $field) {
                $row[$field] = is_string($data[$field] ?? null) ? trim($data[$field]) : '';
            }
            $row['email'] = self::key($row['email']);
            $row['existing_student_id'] = null;
            $row['existing_user_id'] = null;
            $row['action'] = 'new';

            $validator = Validator::make($row, [
                'student_number' => ['required', 'string', 'max:120', 'not_regex:/[\x00-\x1F\x7F]/u'],
                'name' => ['required', 'string', 'max:120', 'not_regex:/[\x00-\x1F\x7F]/u'],
                'email' => ['required', 'email:rfc', 'max:255'],
                'block' => ['required', 'string', 'max:120'],
            ], [], ['student_number' => 'Student ID', 'name' => 'Name', 'email' => 'Email', 'block' => 'Block']);
            if ($validator->fails()) {
                $errors[$number] = array_merge($errors[$number] ?? [], $validator->errors()->all());
            }
            $blockKey = self::key($row['block']);
            if ($row['block'] !== '' && ! isset($blocks[$blockKey])) {
                $errors[$number][] = 'Block must match one of the blocks in this academic setup.';
            } elseif (isset($blocks[$blockKey])) {
                $row['block'] = $blocks[$blockKey];
            }
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
