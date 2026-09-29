<?php

namespace App\Http\Controllers;

use App\Models\AttendanceRecord;
use App\Models\AttendanceSession;
use App\Models\ExcuseRequest;
use App\Models\Setting;
use App\Models\TeacherAssignment;
use App\Services\Access;
use App\Services\AttendanceService;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AttendanceController extends Controller
{
    public function create(Request $r, TeacherAssignment $classroom)
    {
        Access::classroom($r->user(), $classroom, true);
        $data = $r->validate(['date' => 'required|date|before_or_equal:today', 'start_time' => 'required|date_format:H:i', 'end_time' => 'required|date_format:H:i|after:start_time']);
        if ($r->user()->role !== 'admin' && Carbon::parse($data['date'])->lt(today()->subDays(7))) {
            return back()->withErrors(['date' => 'Only administrators can create attendance more than seven days ago.']);
        }
        try {
            $session = AttendanceSession::create($data + ['teacher_assignment_id' => $classroom->id, 'late_threshold' => (int) (Setting::where('key', 'late_threshold')->value('value') ?? 15)]);
        } catch (QueryException $e) {
            if ($e->getCode() === '23000') {
                return back()->withErrors(['date' => 'An attendance session already exists for this class, date, and start time.']);
            } throw $e;
        }

        return redirect('/attendance/sessions/'.$session->id)->with('success', 'Attendance session created.');
    }

    public function show(Request $r, AttendanceSession $session)
    {
        Access::classroom($r->user(), $session->classroom, true);
        $session->load('classroom.block.program', 'classroom.subject', 'records.excuse', 'records.logs.user');
        $enrollments = $session->classroom->enrollments()->with('student.user')->get();
        $recordsByStudent = $session->records->keyBy('student_id');

        return view('attendance.session', compact('session', 'enrollments', 'recordsByStudent'));
    }

    public function record(Request $r, AttendanceSession $session, ?int $studentId = null)
    {
        $data = $r->validate(['student_id' => ($studentId ? 'nullable' : 'required').'|integer|exists:students,id', 'status' => 'required|in:present,late,absent,excused', 'minutes_late' => 'nullable|integer|min:0|max:1440', 'remarks' => 'nullable|string|max:2000', 'reason' => 'nullable|string|max:2000']);
        AttendanceService::record($r->user(), $session, $studentId ?? (int) $data['student_id'], $data, $studentId !== null);

        return back()->with('success', 'Attendance saved with an audit entry.');
    }

    public function excuse(Request $r, AttendanceRecord $record)
    {
        abort_unless($r->user()->role === 'student' && $record->student_id === $r->user()->student?->id, 403);
        Access::classroom($r->user(), $record->session->classroom);
        abort_unless($record->status === 'absent', 422);
        $data = $r->validate(['reason' => 'required|string|min:10|max:2000']);
        if ($record->excuse) {
            return back()->withErrors(['reason' => 'An excuse has already been submitted for this attendance record.']);
        }
        ExcuseRequest::create($data + ['attendance_record_id' => $record->id, 'status' => 'pending']);

        return back()->with('success', 'Excuse submitted for review.');
    }

    public function review(Request $r, ExcuseRequest $excuse)
    {
        $data = $r->validate(['status' => 'required|in:approved,rejected', 'review_note' => 'required|string|max:2000']);
        DB::transaction(function () use ($r, $excuse, $data) {
            $excuse = ExcuseRequest::whereKey($excuse->id)->lockForUpdate()->firstOrFail();
            abort_unless($excuse->status === 'pending', 422);
            AttendanceService::record($r->user(), $excuse->record->session, $excuse->record->student_id, ['status' => $data['status'] === 'approved' ? 'excused' : 'absent', 'minutes_late' => null, 'remarks' => $data['review_note'], 'reason' => 'Excuse '.$data['status'].': '.$data['review_note']], true);
            $excuse->update($data);
        });

        return back()->with('success','Excuse reviewed.');
    }
}
