<?php

namespace App\Services;

use App\Models\AttendanceLog;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSession;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AttendanceService
{
    public static function rate($records): ?float
    {
        $applicable = $records->whereIn('status', ['present', 'late', 'absent']);

        return $applicable->count() ? round($applicable->whereIn('status', ['present', 'late'])->count() / $applicable->count() * 100, 1) : null;
    }

    public static function editable(User $user, AttendanceSession $session): void
    {
        Access::classroom($user, $session->classroom, true);
        if (! $user->isAcademicAdmin() && $session->date->lt(today()->subDays(7))) {
            throw ValidationException::withMessages(['date' => 'Attendance older than seven days requires an administrator correction.']);
        }
    }

    public static function record(User $user, AttendanceSession $session, int $studentId, array $data, bool $update = false): AttendanceRecord
    {
        self::editable($user, $session);
        abort_unless($session->classroom->enrollments()->where('student_id', $studentId)->exists(), 403);
        $data = self::prepare($user, $session, $data);

        return DB::transaction(function () use ($user, $session, $studentId, $data, $update) {
            AttendanceSession::whereKey($session->id)->lockForUpdate()->first();
            $record = AttendanceRecord::where('attendance_session_id', $session->id)->where('student_id', $studentId)->first();
            if ($record && ! $update) {
                throw ValidationException::withMessages(['student_id' => 'Attendance record already exists.']);
            }
            if (! $record && $update) {
                abort(404);
            }

            return self::persist($user, $session, $studentId, $data, $record);
        });
    }

    public static function recordMany(User $user, AttendanceSession $session, array $rows): int
    {
        self::editable($user, $session);

        return DB::transaction(function () use ($user, $session, $rows) {
            AttendanceSession::whereKey($session->id)->lockForUpdate()->firstOrFail();
            $enrolled = $session->classroom->enrollments()->pluck('student_id')->all();
            $records = $session->records()->lockForUpdate()->get()->keyBy('student_id');
            $prepared = [];
            foreach ($rows as $row) {
                abort_unless(in_array((int) $row['student_id'], $enrolled), 403);
                $prepared[] = self::prepare($user, $session, $row);
            }
            $changed = 0;
            foreach ($prepared as $row) {
                $record = $records->get($row['student_id']);
                if ($record) {
                    $candidate = clone $record;
                    $candidate->fill(collect($row)->only(['status', 'minutes_late', 'remarks'])->all());
                    if (! $candidate->isDirty()) {
                        continue;
                    }
                }
                self::persist($user, $session, (int) $row['student_id'], $row, $record);
                $changed++;
            }

            return $changed;
        });
    }

    private static function prepare(User $user, AttendanceSession $session, array $data): array
    {
        if ($user->isAcademicAdmin() && blank($data['reason'] ?? null)) {
            throw ValidationException::withMessages(['reason' => 'Administrator corrections require a reason.']);
        }
        if (isset($data['minutes_late']) && in_array($data['status'], ['present', 'late'])) {
            $data['status'] = $data['minutes_late'] >= $session->late_threshold ? 'late' : 'present';
        }

        return $data;
    }

    private static function persist(User $user, AttendanceSession $session, int $studentId, array $data, ?AttendanceRecord $record): AttendanceRecord
    {
        $update = $record !== null;
        $before = $record?->only(['status', 'minutes_late', 'remarks']);
        $record ??= new AttendanceRecord(['attendance_session_id' => $session->id, 'student_id' => $studentId]);
        $record->fill(collect($data)->only(['status', 'minutes_late', 'remarks'])->all())->save();
        AttendanceLog::create(['attendance_record_id' => $record->id, 'user_id' => $user->id, 'before' => $before, 'after' => $record->only(['status', 'minutes_late', 'remarks']), 'reason' => $data['reason'] ?? ($update ? 'Teacher correction' : 'Initial attendance')]);

        return $record;
    }
}
