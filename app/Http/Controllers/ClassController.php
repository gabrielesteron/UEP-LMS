<?php

namespace App\Http\Controllers;

use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\AttendanceSession;
use App\Models\Grade;
use App\Models\Student;
use App\Models\TeacherAssignment;
use App\Notifications\PortalNotice;
use App\Services\Access;
use App\Services\ClassContent;
use App\Services\Files;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class ClassController extends Controller
{
    public function show(Request $r, TeacherAssignment $classroom)
    {
        Access::classroom($r->user(), $classroom);
        $r->validate(['q' => 'nullable|string|max:100']);
        $classroom->load('block.program', 'block.academicYear', 'subject', 'teacher.user');
        $student = $r->user()->role === 'student';
        $content = [];
        foreach (ClassContent::all() as $kind => [$model]) {
            $content[$kind] = $model::where('teacher_assignment_id', $classroom->id)->when($student, fn ($q) => $q->where('status', 'published'))->when($r->filled('q'), fn ($q) => $q->where('title', 'like', '%'.$r->q.'%'))->orderBy($kind === 'lessons' ? 'position' : 'id')->get();
        }
        $students = $student ? collect() : $classroom->enrollments()->with('student.user')->get()->pluck('student')->filter(fn ($s) => $s->user);
        if (! $student && $r->filled('q')) {
            $students = $students->filter(fn ($s) => str_contains(strtolower($s->user->name.' '.$s->student_number), strtolower($r->q)));
        }
        $sessions = $student ? collect() : AttendanceSession::where('teacher_assignment_id', $classroom->id)->orderByDesc('date')->get();

        return view('classes.show', compact('classroom', 'content', 'students', 'student', 'sessions'));
    }

    public function form(Request $r, TeacherAssignment $classroom, string $kind, ?int $id = null)
    {
        Access::classroom($r->user(), $classroom, true);
        [$model,$fields] = ClassContent::definition($kind);
        $row = $id ? $model::where('teacher_assignment_id', $classroom->id)->findOrFail($id) : new $model;

        return view('classes.form', compact('classroom', 'kind', 'fields', 'row'));
    }

    public function save(Request $r, TeacherAssignment $classroom, string $kind, ?int $id = null)
    {
        Access::classroom($r->user(), $classroom, true);
        [$model] = ClassContent::definition($kind);
        $row = $id ? $model::where('teacher_assignment_id', $classroom->id)->findOrFail($id) : new $model;
        $data = $r->validate(ClassContent::rules($kind, ! $id));
        unset($data['attachment']);
        $publish = $data['status'] === 'published' && $row->status !== 'published';
        DB::transaction(function () use ($row, $kind, $id, &$data, $r, $classroom) {
            if ($id) {
                $row->newQuery()->whereKey($id)->lockForUpdate()->first();
            }
            if ($kind === 'quizzes') {
                if ($id && $row->attempts()->exists()) {
                    throw ValidationException::withMessages(['quiz' => 'A quiz with attempts is locked to preserve grading. Create a new quiz instead.']);
                }
                if ($data['status'] === 'published' && (! $id || ! $row->questions()->exists())) {
                    throw ValidationException::withMessages(['status' => 'Save as draft, add questions, then publish the quiz.']);
                }
            }
            if ($kind === 'assignments' && $id && $row->submissions()->where('score', '>', $data['total_points'])->exists()) {
                throw ValidationException::withMessages(['total_points' => 'Points cannot be lower than an existing score.']);
            }
            $data += Files::upload($r);
            $row->fill($data + ['teacher_assignment_id' => $classroom->id])->save();
            if ($kind === 'assignments') {
                Grade::where('source_type', 'assignment')->where('source_id', $row->id)->update(['title' => $row->title, 'total_points' => $row->total_points]);
            }
        });
        if ($publish && in_array($kind, ['assignments', 'quizzes'])) {
            foreach ($classroom->enrollments()->with('student.user')->get() as $enrollment) {
                $enrollment->student->user?->notify(new PortalNotice('New '.\Illuminate\Support\Str::singular($kind), $row->title, '/classes/'.$classroom->id));
            }
        }

        return redirect('/classes/'.$classroom->id)->with('success', 'Content saved.');
    }

    public function delete(Request $r, TeacherAssignment $classroom, string $kind, int $id)
    {
        Access::classroom($r->user(), $classroom, true);
        [$model] = ClassContent::definition($kind);
        $row = $model::where('teacher_assignment_id', $classroom->id)->findOrFail($id);
        try {
            DB::transaction(function () use ($row, $kind) {
                if ($kind === 'quizzes') {
                    abort_if($row->attempts()->exists(), 422, 'Quizzes with attempts cannot be deleted.');
                    $row->questions()->delete();
                } $row->delete();
            });
        } catch (QueryException $e) {
            return back()->withErrors(['content' => 'This item has student work and cannot be deleted. Unpublish it instead.']);
        }

        return back()->with('success', 'Content deleted.');
    }

    public function assignment(Request $r, Assignment $assignment)
    {
        Access::classroom($r->user(), $assignment->classroom);
        $student = $r->user()->role === 'student';
        abort_if($student && $assignment->status !== 'published', 404);
        $submissions = $assignment->submissions()->with('student.user')->when($student, fn ($q) => $q->where('student_id', $r->user()->student->id))->latest('id')->get();

        return view('classes.assignment', compact('assignment', 'submissions', 'student'));
    }

    public function submit(Request $r, Assignment $assignment)
    {
        abort_unless($r->user()->role === 'student', 403);
        Access::classroom($r->user(), $assignment->classroom);
        abort_unless($assignment->status === 'published', 404);
        $r->validate(['answer' => ($assignment->allow_text ? 'nullable|string|max:100000' : 'prohibited'), 'attachment' => 'nullable|'.Files::RULE]);
        if (! $r->filled('answer') && ! $r->hasFile('attachment')) {
            throw ValidationException::withMessages(['answer' => 'Provide an answer or upload a file.']);
        }
        DB::transaction(function () use ($r, $assignment) {
            Student::whereKey($r->user()->student->id)->lockForUpdate()->first();
            $version = 1 + (int) $assignment->submissions()->where('student_id', $r->user()->student->id)->max('version');
            $late = now()->gt($assignment->due_at);
            $assignment->submissions()->create(['student_id' => $r->user()->student->id, 'version' => $version, 'answer' => $r->input('answer'), 'submitted_at' => now(), 'is_late' => $late, 'status' => $late ? 'late' : 'submitted'] + Files::upload($r));
        });

        return back()->with('success', 'Submission saved. Earlier versions remain available.');
    }

    public function grade(Request $r, AssignmentSubmission $submission)
    {
        Access::classroom($r->user(), $submission->assignment->classroom, true);
        $data = $r->validate(['score' => 'required|numeric|min:0|max:'.$submission->assignment->total_points, 'feedback' => 'nullable|string|max:10000', 'status' => 'required|in:graded,returned']);
        DB::transaction(function () use ($submission, $data) {
            $submission->update($data + ['graded_at' => now()]);
            $latest = $submission->assignment->submissions()->where('student_id', $submission->student_id)->whereNotNull('graded_at')->orderByDesc('version')->first();
            Grade::updateOrCreate(['student_id' => $submission->student_id, 'source_type' => 'assignment', 'source_id' => $submission->assignment_id], ['teacher_assignment_id' => $submission->assignment->teacher_assignment_id, 'title' => $submission->assignment->title, 'score' => $latest->score, 'total_points' => $submission->assignment->total_points]);
        });
        $submission->student->user?->notify(new PortalNotice('Assignment graded', $submission->assignment->title, '/assignments/'.$submission->assignment_id));

        return back()->with('success', 'Score and feedback returned.');
    }

    public function download(Request $r, string $kind, int $id)
    {
        if ($kind === 'submissions') {
            $row = AssignmentSubmission::findOrFail($id);
            Access::classroom($r->user(), $row->assignment->classroom);
            abort_if($r->user()->role === 'student' && $row->student_id !== $r->user()->student->id, 403);
        } else {
            abort_unless(in_array($kind, ['materials', 'assignments']), 404);
            [$model] = ClassContent::definition($kind);
            $row = $model::findOrFail($id);
            Access::classroom($r->user(), $row->classroom);
            abort_if($r->user()->role === 'student' && $row->status !== 'published',404);
        }
        abort_unless($row->path && Storage::disk('local')->exists($row->path),404);

        return Storage::disk('local')->download($row->path,$row->original_name,['X-Content-Type-Options' => 'nosniff']);
    }
}
