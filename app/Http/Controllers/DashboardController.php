<?php

namespace App\Http\Controllers;

use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\AttendanceRecord;
use App\Models\Block;
use App\Models\ClassSchedule;
use App\Models\Grade;
use App\Models\Program;
use App\Models\Quiz;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use App\Services\Access;
use App\Services\AttendanceService;
use App\Services\ClassContent;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $r)
    {
        $user = $r->user();
        $classes = Access::classes($user)->get();
        $ids = $classes->pluck('id');
        $upcoming = Assignment::whereIn('teacher_assignment_id', $ids)->where('status', 'published')->where('due_at', '>=', now())->with('classroom.subject')->orderBy('due_at')->limit(8)->get();
        $quizzes = Quiz::whereIn('teacher_assignment_id', $ids)->where('status', 'published')->where('available_until', '>=', now())->with('classroom.subject')->orderBy('available_from')->limit(8)->get();
        $announcements = Access::announcements($user)->latest()->limit(4)->get();
        $attendance = AttendanceRecord::whereHas('session', fn ($q) => $q->whereIn('teacher_assignment_id', $ids))->when($user->role === 'student', fn ($q) => $q->where('student_id', $user->student?->id ?? 0))->get(['status']);
        $rate = AttendanceService::rate($attendance);
        $stats = $user->role === 'admin' ? ['Students' => Student::count(), 'Teachers' => Teacher::count(), 'Programs' => Program::count(), 'Blocks' => Block::count(), 'Subjects' => Subject::count(), 'Active classes' => $classes->count(), 'Assignments' => Assignment::count(), 'Awaiting grading' => AssignmentSubmission::whereNull('graded_at')->count()] : ['My classes' => $classes->count(), 'Upcoming assignments' => $upcoming->count(), 'Available / upcoming quizzes' => $quizzes->count(), 'Attendance' => $rate === null ? 'N/A' : $rate.'%'];
        $schedules = ClassSchedule::whereIn('teacher_assignment_id', $ids)->with('classroom.subject', 'classroom.block.program', 'classroom.teacher.user')->orderBy('day')->orderBy('start_time')->get();
        $recentUsers = $user->role === 'admin' ? User::latest()->limit(5)->get() : collect();
        $gradeTotals = Grade::whereIn('teacher_assignment_id', $ids)->when($user->role === 'student', fn ($q) => $q->where('student_id', $user->student?->id ?? 0))->selectRaw('SUM(score) as score, SUM(total_points) as total_points')->first();
        $gradeAverage = $gradeTotals->total_points > 0 ? round($gradeTotals->score / $gradeTotals->total_points * 100, 1) : null;

        return view('dashboard', compact('user', 'classes', 'upcoming', 'quizzes', 'announcements', 'stats', 'schedules', 'recentUsers', 'gradeAverage', 'rate'));
    }

    public function classes(Request $r)
    {
        return view('classes.index', ['classes' => Access::classes($r->user())->get()]);
    }

    public function schedule(Request $r)
    {
        return view('schedule', ['schedules' => ClassSchedule::whereIn('teacher_assignment_id', Access::classes($r->user())->pluck('id'))->with('classroom.subject', 'classroom.block.program', 'classroom.teacher.user')->orderBy('day')->orderBy('start_time')->get()]);
    }

    public function search(Request $r)
    {
        $r->validate(['q' => 'nullable|string|max:100']);
        $results = collect();
        if ($r->filled('q')) {
            $classIds = Access::classes($r->user())->pluck('id');
            foreach (ClassContent::all() as $kind => [$model]) {
                $rows = $model::whereIn('teacher_assignment_id', $classIds)->when($r->user()->role === 'student', fn ($q) => $q->where('status', 'published'))->where(fn ($q) => $q->where('title', 'like', '%'.$r->q.'%')->orWhere('description', 'like', '%'.$r->q.'%'))->with('classroom.subject', 'classroom.block')->limit(30)->get();
                foreach ($rows as $row) {
                    $results->push(['title' => $row->title, 'kind' => $kind, 'class' => $row->classroom->label, 'url' => in_array($kind, ['assignments', 'quizzes']) ? '/'.$kind.'/'.$row->id : '/classes/'.$row->teacher_assignment_id.'#'.$kind]);
                }
            }
        }

        return view('search',compact('results'));
    }
}
