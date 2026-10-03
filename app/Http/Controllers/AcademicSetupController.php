<?php

namespace App\Http\Controllers;

use App\Models\AcademicYear;
use App\Models\Program;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\YearLevel;
use App\Services\AcademicSetup;
use App\Services\StudentCsvImport;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AcademicSetupController extends Controller
{
    private function draft(Request $request): array
    {
        $draft = $request->session()->get('academic_setup');
        if (! $draft || ($draft['owner'] ?? null) !== $request->user()->id) {
            $draft = ['owner' => $request->user()->id, 'token' => Str::random(40), 'completed' => 0];
            $request->session()->put('academic_setup', $draft);
        }

        return $draft;
    }

    private function checkToken(Request $request, array $draft): void
    {
        if (! hash_equals($draft['token'], (string) $request->input('draft_token'))) {
            throw ValidationException::withMessages(['setup' => 'This setup has changed or expired. Refresh the page before continuing.']);
        }
    }

    public function show(Request $request)
    {
        $draft = $this->draft($request);
        $step = max(1, min(6, $request->integer('step', 1)));
        if ($step > $draft['completed'] + 1) {
            return redirect('/admin/setup?step='.($draft['completed'] + 1));
        }
        $data = ['draft' => $draft, 'step' => $step, 'steps' => AcademicSetup::STEPS];
        if ($step === 1 || $step === 6) {
            $data += ['academicYears' => AcademicYear::orderByDesc('starts_on')->limit(200)->get(), 'programs' => Program::orderBy('name')->limit(200)->get(), 'yearLevels' => YearLevel::orderBy('level')->limit(200)->get()];
        }
        if ($step === 3) {
            $data['subjectChoices'] = Subject::orderBy('code')->limit(200)->get(['id', 'code', 'name', 'units']);
        }
        if ($step === 4 || $step === 6) {
            $data['teachers'] = Teacher::with('user')->whereHas('user', fn ($q) => $q->where('role', 'teacher'))->orderBy('id')->limit(1000)->get();
        }
        if ($step === 5) {
            $data['students'] = Student::with(['user', 'block'])->whereHas('user', function ($q) use ($request) {
                $q->where('role', 'student');
                if ($request->filled('q')) {
                    $q->where(fn ($q) => $q->where('name', 'like', '%'.$request->string('q')->limit(100).'%')->orWhere('email', 'like', '%'.$request->string('q')->limit(100).'%'));
                }
            })->orderBy('student_number')->paginate(25)->withQueryString();
            $csv = $draft['csv_preview'] ?? ['rows' => [], 'errors' => [], 'valid_count' => 0, 'invalid_count' => 0];
            $page = max(1, $request->integer('csv_page', 1));
            $data['csv'] = $csv;
            $data['csvRows'] = new LengthAwarePaginator(array_slice($csv['rows'], ($page - 1) * 50, 50), count($csv['rows']), 50, $page, ['path' => '/admin/setup', 'pageName' => 'csv_page', 'query' => $request->except('csv_page')]);
        }
        if ($step === 6) {
            if (! empty($draft['csv_preview']['errors'])) {
                return redirect('/admin/setup?step=5')->withErrors(['csv' => 'Correct or remove the invalid CSV preview before review.']);
            }
            try {
                $validated = AcademicSetup::validateDraft($draft);
            } catch (ValidationException $e) {
                return redirect('/admin/setup?step=5')->withErrors($e->errors());
            }
            $draft = array_merge($draft, $validated);
            unset($draft['review_hash']);
            $draft['review_hash'] = hash('sha256', json_encode($draft));
            $request->session()->put('academic_setup', $draft);
            $data['draft'] = $draft;
            $data['studentCount'] = count($validated['students']) + count($validated['csv_rows']);
            $data['newAccountCount'] = count(array_filter($validated['csv_rows'], fn ($row) => ! $row['existing_user_id']));
        }

        return view('admin.setup.index', $data);
    }

    public function save(Request $request, int $step)
    {
        $draft = $this->draft($request);
        $this->checkToken($request, $draft);
        if ($step > $draft['completed'] + 1) {
            return redirect('/admin/setup?step='.($draft['completed'] + 1));
        }
        unset($draft['review_hash']);
        if ($step <= 4) {
            $normalized = AcademicSetup::normalizeStep($step, $request->all());
            if ($step === 4) {
                AcademicSetup::validateDraft(array_merge($draft, $normalized, ['students' => [], 'csv_rows' => []]));
            }
            if ($step <= $draft['completed']) {
                // Downstream choices refer to row indexes; discard them when earlier rows change.
                foreach ([2 => ['blocks', 'source_block_ids', 'copy_schedules'], 3 => ['subjects'], 4 => ['assignments'], 5 => ['students', 'csv_rows', 'csv_preview']] as $later => $keys) {
                    if ($later > $step) {
                        foreach ($keys as $key) {
                            unset($draft[$key]);
                        }
                    }
                }
                if ($step === 2 && isset($draft['source_block_ids']) && count($normalized['blocks']) !== count($draft['source_block_ids'])) {
                    unset($draft['source_block_ids'], $draft['copy_schedules']);
                }
                $draft['completed'] = $step;
            }
            $draft = array_merge($draft, $normalized);
            if ($step === 1 && ! empty($draft['source'])) {
                $draft['copy_schedules'] = $request->boolean('copy_schedules');
            }
        } else {
            $request->validate(['visible_ids' => 'nullable|array|max:25', 'visible_ids.*' => 'integer', 'selected' => 'nullable|array|max:25', 'selected.*' => 'integer', 'placements' => 'nullable|array|max:25', 'placements.*' => 'integer|min:0', 'csv_file' => 'nullable|file|max:1024']);
            $visible = array_map('intval', $request->input('visible_ids', []));
            $selected = array_map('intval', $request->input('selected', []));
            $people = collect($draft['students'] ?? [])->keyBy('id');
            foreach ($visible as $id) {
                $people->forget($id);
                if (in_array($id, $selected, true)) {
                    $people->put($id, ['id' => $id, 'block' => (int) $request->input('placements.'.$id, -1)]);
                }
            }
            $draft['students'] = $people->values()->all();
            if ($request->input('action') === 'clear_csv') {
                unset($draft['csv_preview'], $draft['csv_rows']);
            } elseif ($request->hasFile('csv_file')) {
                $draft['csv_preview'] = StudentCsvImport::preview($request->file('csv_file'), array_column($draft['blocks'], 'name'));
                $draft['csv_rows'] = $draft['csv_preview']['rows'];
            }
            $request->session()->put('academic_setup', $draft);
            if ($request->input('action') !== 'continue') {
                return redirect('/admin/setup?step=5')->with('success', 'Selection and CSV preview saved. No users or academic records have been created.');
            }
            if (! empty($draft['csv_preview']['errors'])) {
                throw ValidationException::withMessages(['csv' => 'Correct or remove all invalid CSV rows before continuing.']);
            }
            AcademicSetup::validateDraft($draft);
        }
        $draft['completed'] = max($draft['completed'], $step);
        $request->session()->put('academic_setup', $draft);

        return redirect('/admin/setup?step='.($step + 1));
    }

    public function duplicate(Request $request)
    {
        $draft = $this->draft($request);
        $this->checkToken($request, $draft);
        $request->validate(['source' => 'required|array']);
        $scope = Validator::make($request->input('source', []), ['academic_year_id' => 'required|integer', 'program_id' => 'required|integer', 'year_level_id' => 'required|integer', 'semester' => 'required|integer'])->validate();
        $copy = AcademicSetup::duplicate($scope, $request->boolean('copy_students'), $request->boolean('copy_schedules'));
        $draft = ['owner' => $request->user()->id, 'token' => Str::random(40), 'completed' => 0] + $copy;
        $request->session()->put('academic_setup', $draft);

        return redirect('/admin/setup')->with('success', 'Previous setup loaded for review. Choose the target academic year and semester, then confirm every step. Nothing has been created.');
    }

    public function create(Request $request)
    {
        $previous = $request->session()->get('academic_setup_result');
        if ($previous && hash_equals($previous['token'], (string) $request->input('draft_token')) && $previous['owner'] === $request->user()->id) {
            return redirect('/admin/manage/teacher-assignments');
        }
        $draft = $this->draft($request);
        $this->checkToken($request, $draft);
        $request->validate(['confirm' => 'accepted', 'review_hash' => 'required|string']);
        if ($draft['completed'] < 5 || empty($draft['review_hash']) || ! hash_equals($draft['review_hash'], $request->input('review_hash')) || ! empty($draft['csv_preview']['errors'])) {
            throw ValidationException::withMessages(['setup' => 'Review the completed setup before creating it.']);
        }
        try {
            $result = AcademicSetup::create($draft);
        } catch (QueryException $e) {
            if (in_array((string) $e->getCode(), ['23000', '23505'])) {
                return redirect('/admin/setup?step=6')->withErrors(['setup' => 'A selected record changed or a matching record already exists. No setup was created. Review the current data and try again.']);
            }
            throw $e;
        }
        $request->session()->forget('academic_setup');
        $request->session()->put('academic_setup_result', ['token' => $draft['token'], 'owner' => $request->user()->id, 'summary' => $result]);

        return redirect('/admin/manage/teacher-assignments')->with('success', "Academic setup created: {$result['blocks']} blocks, {$result['classes']} classes, {$result['students']} students, {$result['enrollments']} enrollments and {$result['schedules']} schedules. {$result['invitations_queued']} activation invitations queued. Previous academic records were preserved.");
    }

    public function restart(Request $request)
    {
        $request->session()->forget('academic_setup');

        return redirect('/admin/setup')->with('success', 'Setup draft cleared. Saved records were not changed.');
    }

    public function template()
    {
        return response(StudentCsvImport::template(), 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="uep-student-template.csv"']);
    }
}
