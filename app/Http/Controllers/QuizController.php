<?php

namespace App\Http\Controllers;

use App\Models\Grade;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Services\Access;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class QuizController extends Controller
{
    public function show(Request $r, Quiz $quiz)
    {
        Access::classroom($r->user(), $quiz->classroom);
        $student = $r->user()->role === 'student';
        abort_if($student && $quiz->status !== 'published', 404);
        $attempts = $quiz->attempts()->with('student.user')->when($student, fn ($q) => $q->where('student_id', $r->user()->student->id))->latest('id')->get();
        foreach ($attempts as $attempt) {
            if (! $attempt->completed_at && now()->gte($attempt->expires_at)) {
                $this->finish($attempt, []);
            }
        }

        return view('classes.quiz', compact('quiz', 'student', 'attempts'));
    }

    public function question(Request $r, Quiz $quiz, ?int $id = null)
    {
        Access::classroom($r->user(), $quiz->classroom, true);
        $data = $r->validate(['question' => 'required|string|max:10000', 'option_a' => 'required|string|max:255', 'option_b' => 'required|string|max:255', 'option_c' => 'required|string|max:255', 'option_d' => 'required|string|max:255', 'correct_answer' => 'required|in:a,b,c,d', 'points' => 'required|numeric|min:0.01|max:1000']);
        DB::transaction(function () use ($quiz, $data, $id) {
            $quiz = Quiz::whereKey($quiz->id)->lockForUpdate()->firstOrFail();
            if ($quiz->status !== 'draft' || $quiz->attempts()->exists()) {
                throw ValidationException::withMessages(['quiz' => 'Questions can only be changed in a draft quiz without attempts.']);
            }
            if ($id) {
                $quiz->questions()->findOrFail($id)->update($data);
            } else {
                $quiz->questions()->create($data);
            }
        });

        return back()->with('success', 'Question saved.');
    }

    public function deleteQuestion(Request $r, Quiz $quiz, int $id)
    {
        Access::classroom($r->user(), $quiz->classroom, true);
        DB::transaction(function () use ($quiz, $id) {
            $quiz = Quiz::whereKey($quiz->id)->lockForUpdate()->firstOrFail();
            abort_unless($quiz->status === 'draft' && ! $quiz->attempts()->exists(), 422);
            $quiz->questions()->findOrFail($id)->delete();
        });

        return back()->with('success', 'Question deleted.');
    }

    public function start(Request $r, Quiz $quiz)
    {
        abort_unless($r->user()->role === 'student', 403);
        Access::classroom($r->user(), $quiz->classroom);
        $attempt = DB::transaction(function () use ($r, $quiz) {
            $quiz = Quiz::whereKey($quiz->id)->lockForUpdate()->firstOrFail();
            abort_unless($quiz->status === 'published' && now()->between($quiz->available_from, $quiz->available_until), 403, 'This quiz is not available.');
            abort_unless($quiz->questions()->exists(), 422, 'This quiz has no questions.');
            $q = $quiz->attempts()->where('student_id', $r->user()->student->id);
            if ($current = (clone $q)->whereNull('completed_at')->first()) {
                return $current;
            }
            if ($q->count() >= $quiz->max_attempts) {
                throw ValidationException::withMessages(['quiz' => 'No attempts remaining.']);
            }

            return $quiz->attempts()->create(['student_id' => $r->user()->student->id, 'attempt_number' => $q->count() + 1, 'started_at' => now(), 'expires_at' => now()->addMinutes($quiz->time_limit)->min($quiz->available_until)]);
        });

        return redirect('/attempts/'.$attempt->id);
    }

    private function authorizeAttempt(Request $r, QuizAttempt $attempt): void
    {
        Access::classroom($r->user(), $attempt->quiz->classroom);
        abort_if($r->user()->role === 'student' && $attempt->student_id !== $r->user()->student->id, 403);
    }

    public function attempt(Request $r, QuizAttempt $attempt)
    {
        $this->authorizeAttempt($r, $attempt);
        if (! $attempt->completed_at && now()->gte($attempt->expires_at)) {
            $this->finish($attempt, []);
        }
        $questions = $attempt->quiz->questions()->get();

        return view('classes.attempt', compact('attempt', 'questions'));
    }

    public function submit(Request $r, QuizAttempt $attempt)
    {
        abort_unless($r->user()->role === 'student', 403);
        $this->authorizeAttempt($r, $attempt);
        $r->validate(['answers' => 'nullable|array', 'answers.*' => 'nullable|in:a,b,c,d']);
        $this->finish($attempt, $r->input('answers', []));

        return redirect('/attempts/'.$attempt->id)->with('success', 'Quiz scored. Answers submitted after expiry receive zero points.');
    }

    public function finish(QuizAttempt $attempt, array $answers): void
    {
        DB::transaction(function () use ($attempt, $answers) {
            $locked = QuizAttempt::whereKey($attempt->id)->lockForUpdate()->firstOrFail();
            if ($locked->completed_at) {
                $attempt->refresh();

                return;
            }
            $expired = now()->gt($locked->expires_at);
            $score = 0;
            foreach ($locked->quiz->questions as $question) {
                $answer = $expired ? null : ($answers[$question->id] ?? null);
                $points = $answer === $question->correct_answer ? (float) $question->points : 0;
                $score += $points;
                $locked->answers()->create(['quiz_question_id' => $question->id, 'answer' => $answer, 'points' => $points]);
            }
            $locked->update(['completed_at' => now(), 'score' => $score]);
            $best = $locked->quiz->attempts()->where('student_id', $locked->student_id)->whereNotNull('completed_at')->max('score');
            Grade::updateOrCreate(['student_id' => $locked->student_id, 'source_type' => 'quiz', 'source_id' => $locked->quiz_id], ['teacher_assignment_id' => $locked->quiz->teacher_assignment_id, 'title' => $locked->quiz->title, 'score' => $best, 'total_points' => $locked->quiz->questions()->sum('points')]);
            $attempt->refresh();
        });
    }
}
