<?php

namespace App\Services;

use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\Grade;
use App\Models\Student;
use App\Models\User;
use App\Notifications\PortalNotice;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AssignmentGrading
{
    public static function rules(Assignment $assignment, bool $optional = false): array
    {
        return ['score' => ($optional ? 'nullable' : 'required').'|numeric|min:0|max:'.$assignment->total_points,
            'feedback' => 'nullable|string|max:10000', 'status' => 'required|in:graded,returned'];
    }

    public static function save(AssignmentSubmission $submission, array $data): void
    {
        DB::transaction(function () use ($submission, $data) {
            self::storeSubmission($submission, $data);
            $latest = $submission->assignment->submissions()->where('student_id', $submission->student_id)->whereNotNull('graded_at')->orderByDesc('version')->first();
            Grade::updateOrCreate(self::gradeKey($submission), self::gradeValues($submission, $latest->score));
        });
    }

    public static function saveMany(User $user, Assignment $assignment, array $rows): int
    {
        Access::classroom($user, $assignment->classroom, true);
        $graded = DB::transaction(function () use ($assignment, $rows) {
            $submitted = $assignment->submissions()->whereIn('id', array_keys($rows))->get();
            abort_unless($submitted->count() === count($rows), 403);
            // Submission creation locks the student too, so a newer version cannot slip into this batch.
            Student::whereIn('id', $submitted->pluck('student_id'))->orderBy('id')->lockForUpdate()->get();
            $latestIds = $assignment->submissions()->selectRaw('MAX(id) AS id')->groupBy('student_id')->pluck('id')->all();
            foreach ($submitted as $submission) {
                if (! in_array($submission->id, $latestIds)) {
                    throw ValidationException::withMessages(['grades' => 'A student has submitted newer work. Reload this assignment before saving grades.']);
                }
            }
            $graded = new Collection;
            $gradeRows = [];
            foreach ($submitted as $submission) {
                $data = $rows[$submission->id];
                if (($data['score'] ?? null) === null) {
                    continue;
                }
                if ($submission->graded_at && (float) $submission->score === (float) $data['score']
                    && (string) $submission->feedback === (string) (array_key_exists('feedback', $data) ? $data['feedback'] : $submission->feedback) && $submission->status === $data['status']) {
                    continue;
                }
                $submission->setRelation('assignment', $assignment);
                self::storeSubmission($submission, $data);
                // Every accepted row is the latest submission, so it is also the latest graded version.
                $gradeRows[] = self::gradeKey($submission) + self::gradeValues($submission, $submission->score);
                $graded->push($submission);
            }
            if ($gradeRows) {
                Grade::upsert($gradeRows, ['student_id', 'source_type', 'source_id'], ['teacher_assignment_id', 'title', 'score', 'total_points', 'updated_at']);
            }

            return $graded;
        });
        $graded->load('student.user');
        foreach ($graded as $submission) {
            self::notify($submission);
        }

        return $graded->count();
    }

    public static function notify(AssignmentSubmission $submission): void
    {
        $submission->student->user?->notify(new PortalNotice('Assignment graded', $submission->assignment->title, '/assignments/'.$submission->assignment_id));
    }

    private static function storeSubmission(AssignmentSubmission $submission, array $data): void
    {
        $submission->update($data + ['graded_at' => now()]);
    }

    private static function gradeKey(AssignmentSubmission $submission): array
    {
        return ['student_id' => $submission->student_id, 'source_type' => 'assignment', 'source_id' => $submission->assignment_id];
    }

    private static function gradeValues(AssignmentSubmission $submission, mixed $score): array
    {
        return ['teacher_assignment_id' => $submission->assignment->teacher_assignment_id, 'title' => $submission->assignment->title,
            'score' => $score, 'total_points' => $submission->assignment->total_points];
    }
}
