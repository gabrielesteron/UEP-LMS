<?php

namespace App\Http\Controllers;

use App\Models\AcademicYear;
use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\AttendanceRecord;
use App\Models\Block;
use App\Models\ClassSchedule;
use App\Models\Grade;
use App\Models\LearningMaterial;
use App\Models\Lesson;
use App\Models\Program;
use App\Models\Quiz;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\TeacherAssignment;
use App\Models\User;
use App\Models\YearLevel;
use App\Services\Access;
use App\Services\ClassContent;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $r)
    {
        $user = $r->user();
        if ($user->role === 'student') {
            $user->loadMissing('student.block.program', 'student.block.yearLevel', 'student.block.academicYear');
        }
        $classQuery = Access::classes($user);
        $classCount = (clone $classQuery)->count();
        $classes = (clone $classQuery)->orderBy('id')->limit(6)->get();
        // Keep scopes in SQL so a dashboard never needs to load every class or roster.
        $ids = (clone $classQuery)->withoutEagerLoads()->select('teacher_assignments.id');
        $studentId = $user->role === 'student' ? ($user->student?->id ?? 0) : 0;
        $assignmentQuery = Assignment::whereIn('teacher_assignment_id', $ids)->where('status', 'published');
        $upcoming = $user->role === 'student' ? collect() : (clone $assignmentQuery)->where('due_at', '>=', now())->with('classroom.subject')->orderBy('due_at')->limit(8)->get();
        $quizzes = config('lms.show_advanced_features') ? Quiz::whereIn('teacher_assignment_id', $ids)->where('status', 'published')->where('available_until', '>=', now())->with('classroom.subject')->orderBy('available_from')->limit(8)->get() : collect();
        $announcements = Access::announcements($user)->latest()->limit(4)->get();
        $attendance = AttendanceRecord::whereHas('session', fn ($q) => $q->whereIn('teacher_assignment_id', $ids))->when($user->role === 'student', fn ($q) => $q->where('student_id', $studentId))
            ->selectRaw("SUM(CASE WHEN status IN ('present', 'late') THEN 1 ELSE 0 END) AS attended, SUM(CASE WHEN status IN ('present', 'late', 'absent') THEN 1 ELSE 0 END) AS applicable")->first();
        // Same calculation as AttendanceService::rate: excused records do not lower attendance.
        $rate = $attendance->applicable > 0 ? round($attendance->attended / $attendance->applicable * 100, 1) : null;
        $stats = $user->role === 'admin' ? ['Students' => Student::count(), 'Teachers' => Teacher::count(), 'Programs' => Program::count(), 'Blocks' => Block::count(), 'Subjects' => Subject::count(), 'Active classes' => $classCount, 'Assignments' => Assignment::count(), 'Awaiting grading' => AssignmentSubmission::whereNull('graded_at')->whereIn('id', AssignmentSubmission::selectRaw('MAX(id)')->groupBy('assignment_id', 'student_id'))->count()] : ['My classes' => $classCount, 'Upcoming assignments' => $user->role === 'student' ? (clone $assignmentQuery)->where('due_at', '>=', now())->count() : $upcoming->count(), ...(config('lms.show_advanced_features') ? ['Available / upcoming quizzes' => $quizzes->count()] : []), 'Attendance' => $rate === null ? 'N/A' : $rate.'%'];
        $schedules = $user->role === 'student' ? collect() : ClassSchedule::whereIn('teacher_assignment_id', $ids)->with('classroom.subject', 'classroom.block.program', 'classroom.teacher.user')->orderBy('day')->orderBy('start_time')->limit(12)->get();
        $recentUsers = $user->role === 'admin' ? User::latest()->limit(5)->get() : collect();
        $gradeQuery = Grade::whereIn('teacher_assignment_id', $ids)->when($user->role === 'student', fn ($q) => $q->where('student_id', $studentId));
        $gradeTotals = (clone $gradeQuery)->selectRaw('SUM(score) as score, SUM(total_points) as total_points')->first();
        $gradeAverage = $gradeTotals->total_points > 0 ? round($gradeTotals->score / $gradeTotals->total_points * 100, 1) : null;
        $needsAttention = $user->role === 'admin' ? $this->adminAttention() : collect();
        $studentAttention = collect();
        $recentActivity = collect();
        if ($user->role === 'student') {
            $latestIds = AssignmentSubmission::selectRaw('MAX(id)')->where('student_id', $studentId)->groupBy('assignment_id');
            $studentAttention = (clone $assignmentQuery)->where(function ($q) use ($studentId, $latestIds) {
                $q->whereDoesntHave('submissions', fn ($q) => $q->where('student_id', $studentId))
                    ->orWhereHas('submissions', fn ($q) => $q->whereIn('id', $latestIds)->where('status', 'returned'));
            })->with('classroom.subject')->addSelect(['latest_submission_status' => AssignmentSubmission::select('status')->whereColumn('assignment_id', 'assignments.id')->where('student_id', $studentId)->latest('id')->limit(1)])
                ->orderBy('due_at')->limit(6)->get();
            foreach ([Lesson::class => ['New lesson', 'lessons'], LearningMaterial::class => ['New material', 'materials'], Assignment::class => ['New assignment', 'assignments']] as $model => [$label, $kind]) {
                $items = $model::whereIn('teacher_assignment_id', $ids)->where('status', 'published')->with('classroom.subject')->latest('updated_at')->latest('id')->limit(2)->get();
                foreach ($items as $item) {
                    $recentActivity->push(['label' => $label, 'title' => $item->title, 'subject' => $item->classroom->subject->code, 'date' => $item->updated_at, 'url' => $kind === 'assignments' ? '/assignments/'.$item->id : '/classes/'.$item->teacher_assignment_id.'#'.$kind, 'score' => null]);
                }
            }
            foreach ((clone $gradeQuery)->with('classroom.subject')->latest('updated_at')->latest('id')->limit(2)->get() as $grade) {
                $recentActivity->push(['label' => 'Recent grade', 'title' => $grade->title, 'subject' => $grade->classroom->subject->code, 'date' => $grade->updated_at, 'url' => '/classes/'.$grade->teacher_assignment_id.'/gradebook', 'score' => $grade->score.' / '.$grade->total_points]);
            }
            $recentActivity = $recentActivity->sortByDesc('date')->take(8)->values();
        }

        return view('dashboard', compact('user', 'classes', 'classCount', 'upcoming', 'quizzes', 'announcements', 'stats', 'schedules', 'recentUsers', 'gradeAverage', 'rate', 'needsAttention', 'studentAttention', 'recentActivity'));
    }

    private function adminAttention()
    {
        $items = collect();
        if (! AcademicYear::exists() || ! Program::exists() || ! YearLevel::exists()) {
            $items->push(['label' => 'Complete academic setup', 'detail' => 'Choose an academic year, program and year level before creating classes.', 'count' => null, 'url' => '/admin/setup']);
        }
        foreach ([
            ['Blocks without subjects', 'Assign subjects and teachers to these blocks.', Block::doesntHave('classes')->count(), '/admin/manage/teacher-assignments'],
            ['Classes with unavailable teachers', 'Review archived, inactive or suspended teacher accounts.', TeacherAssignment::whereDoesntHave('teacher.user', fn ($q) => $q->where('status', 'active')->where('role', 'teacher'))->count(), '/admin/manage/users?role=teacher'],
            ['Students missing required information', 'Add the student profile, student number or block placement.', User::where('role', 'student')->where(fn ($q) => $q->where('name', '')->orWhere('email', '')->orWhereDoesntHave('student')->orWhereHas('student', fn ($q) => $q->where('student_number', '')->orWhereDoesntHave('block')))->count(), '/admin/manage/students'],
            ['Classes missing schedules', 'Add the weekly day, time and room for each class.', TeacherAssignment::doesntHave('schedules')->count(), '/admin/manage/schedules'],
        ] as [$label, $detail, $count, $url]) {
            if ($count > 0) {
                $items->push(compact('label', 'detail', 'count', 'url'));
            }
        }

        return $items;
    }

    public function classes(Request $r)
    {
        return view('classes.index', ['classes' => Access::classes($r->user())->orderBy('block_id')->orderBy('id')->paginate(18)->withQueryString()]);
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

        return view('search', compact('results'));
    }
}
