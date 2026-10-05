<?php

namespace App\Http\Controllers;

use App\Http\Requests\CatalogRequest;
use App\Models\AcademicYear;
use App\Models\Block;
use App\Models\Enrollment;
use App\Models\Setting;
use App\Models\Student;
use App\Models\Subject;
use App\Models\TeacherAssignment;
use App\Models\User;
use App\Notifications\Invitation;
use App\Services\Catalog;
use App\Services\ScheduleConflicts;
use App\Services\UserAccounts;
use App\Services\AdministrativeAudit;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class AdminController extends Controller
{
    public function index(Request $r, string $resource, bool $adminsOnly = false)
    {
        [$model,$fields] = Catalog::definition($resource);
        if ($resource === 'users') {
            Gate::authorize('system-administration');
            $r->validate(['q' => 'nullable|string|max:120', 'role' => 'nullable|in:super_admin,admin,teacher,student', 'status' => 'nullable|in:active,inactive,suspended', 'archived' => 'nullable|boolean']);
            $q = $r->boolean('archived') ? User::onlyTrashed() : User::query();
            $q->when($adminsOnly, fn ($q) => $q->where('role', 'admin'))
                ->when(! $adminsOnly && $r->filled('role'), fn ($q) => $q->where('role', $r->role))
                ->when($r->filled('status'), fn ($q) => $q->where('status', $r->status))
                ->when($r->filled('q'), fn ($q) => $q->where(fn ($q) => $q->where('name', 'like', '%'.$r->q.'%')->orWhere('email', 'like', '%'.$r->q.'%')));
            return view('admin.users', ['rows' => $q->latest('id')->paginate(15)->withQueryString(), 'adminsOnly' => $adminsOnly]);
        }
        $q = $model::query();
        if ($resource === 'subjects') {
            $q->with(['program', 'yearLevel', 'prerequisites']);
        }
        if ($r->filled('q')) {
            $q->where(function ($q) use ($fields, $r) {
                foreach ($fields as $field => $type) {
                    if (in_array($type, ['text', 'email', 'textarea'])) {
                        $q->orWhere($field, 'like', '%'.$r->q.'%');
                    }
                }
            });
        }
        foreach ($fields as $field => $type) {
            if ($r->filled($field)) {
                $q->where($field, $r->input($field));
            }
        }

        $fieldChoices = collect($fields)->map(fn ($type) => Catalog::choices($type))->all();

        return view('admin.index', ['resource' => $resource, 'fields' => $fields, 'fieldChoices' => $fieldChoices, 'rows' => $q->latest('id')->paginate(15)->withQueryString()]);
    }

    public function form(string $resource, ?int $id = null)
    {
        [$model,$fields] = Catalog::definition($resource);
        if ($resource === 'users') {
            Gate::authorize('system-administration');
        }
        $row = $id ? $model::findOrFail($id) : new $model;
        if ($resource === 'users' && ! $id && request('role') === 'admin') {
            $row->role = 'admin';
        }

        $fieldChoices = collect($fields)->map(fn ($type) => Catalog::choices($type))->all();
        $missingRelated = collect($fields)->contains(fn ($type, $field) => ! ($resource === 'subjects' && in_array($field, ['program_id', 'year_level_id', 'semester'])) && ! in_array($type, ['text', 'email', 'date', 'time', 'textarea', 'number']) && empty($fieldChoices[$field]));
        if ($resource === 'subjects') {
            $row->load(['prerequisites.program', 'prerequisites.yearLevel']);
        }

        return view('admin.form', compact('resource', 'fields', 'row', 'fieldChoices', 'missingRelated'));
    }

    public function save(CatalogRequest $r, string $resource, ?int $id = null)
    {
        [$model] = Catalog::definition($resource);
        $row = $id ? $model::findOrFail($id) : new $model;
        $data = $r->validated();
        if ($resource === 'users') {
            try {
                $row = UserAccounts::save($r->user(), $data, $id);
            } catch (QueryException $e) {
                if (in_array($e->getCode(), ['23000', '23505'])) {
                    return back()->withInput()->withErrors(['record' => 'A matching account already exists. Check the email and try again.']);
                }
                throw $e;
            }
            if (! $row->email_verified_at && $row->status === 'inactive') {
                try {
                    $this->sendInvitation($row);
                    AdministrativeAudit::record($r->user(), 'invitation.sent', 'user', $row->id);
                } catch (\Throwable $e) {
                    report($e);
                    return redirect('/admin/manage/users')->withErrors(['email' => 'Account saved, but mail delivery failed. Check SMTP and resend the invitation.']);
                }
            }
            return redirect('/admin/manage/users')->with('success', 'Account saved. New accounts must activate using their invitation.');
        }
        if ($resource === 'subjects' && $id && $row->program_id) {
            // Curriculum placement is maintained by the validated importer. Editing
            // titles and credit values must not move courses or prerequisite IDs.
            foreach (['program_id', 'year_level_id', 'semester', 'code'] as $scope) {
                if ((string) ($data[$scope] ?? '') !== (string) $row->$scope) {
                    throw ValidationException::withMessages([$scope => 'Use the curriculum importer to change an imported course code or placement.']);
                }
            }
        }
        if (in_array($resource, ['students', 'teachers'])) {
            $user = User::findOrFail($data['user_id']);
            if ($user->role !== ($resource === 'students' ? 'student' : 'teacher')) {
                throw ValidationException::withMessages(['user_id' => 'Choose an account with the matching role.']);
            }
        }
        if ($resource === 'enrollments') {
            if (Student::findOrFail($data['student_id'])->block_id !== TeacherAssignment::findOrFail($data['teacher_assignment_id'])->block_id) {
                throw ValidationException::withMessages(['student_id' => 'The student must belong to this class block.']);
            }
        }
        if ($resource === 'teacher-assignments') {
            $subject = Subject::findOrFail($data['subject_id']);
            $block = Block::findOrFail($data['block_id']);
            if ($subject->program_id && ($subject->program_id != $block->program_id || $subject->year_level_id != $block->year_level_id || $subject->semester != $block->semester)) {
                throw ValidationException::withMessages(['subject_id' => 'Choose a curriculum subject matching the block Program, Year Level and Semester.']);
            }
        }
        try {
            DB::transaction(function () use ($row, $data, $resource, $id) {
                // Serialize schedule changes on the academic-year row to prevent competing inserts.
                if ($resource === 'schedules') {
                    $class = TeacherAssignment::findOrFail($data['teacher_assignment_id']);
                    AcademicYear::whereKey($class->block->academic_year_id)->lockForUpdate()->first();
                    $conflict = ScheduleConflicts::exists($class, $data, $id);
                    if ($conflict) {
                        throw ValidationException::withMessages(['start_time' => 'Schedule conflicts with this teacher, block, or room.']);
                    }
                }
                if ($resource === 'teacher-assignments' && $id && $row->schedules()->exists() && ($row->teacher_id != $data['teacher_id'] || $row->block_id != $data['block_id'])) {
                    throw ValidationException::withMessages(['teacher_id' => 'Remove schedules before reassigning this class so conflicts can be checked.']);
                }
                if ($resource === 'teacher-assignments' && $id && $row->block_id != $data['block_id']) {
                    throw ValidationException::withMessages(['block_id' => 'Create a new class for a different block to preserve academic history.']);
                }
                $oldBlock = $resource === 'students' ? $row->block_id : null;
                $row->fill($data)->save();
                if ($resource === 'students') {
                    if ($oldBlock && $oldBlock != $row->block_id) {
                        $row->enrollments()->delete();
                    }
                    foreach (TeacherAssignment::where('block_id', $row->block_id)->get() as $class) {
                        Enrollment::firstOrCreate(['student_id' => $row->id, 'teacher_assignment_id' => $class->id]);
                    }
                }
                if ($resource === 'teacher-assignments') {
                    foreach (Student::where('block_id', $row->block_id)->get() as $student) {
                        Enrollment::firstOrCreate(['student_id' => $student->id, 'teacher_assignment_id' => $row->id]);
                    }
                }
            });
        } catch (QueryException $e) {
            if (in_array($e->getCode(), ['23000', '23505'])) {
                return back()->withInput()->withErrors(['record' => 'A matching record already exists or a related record prevents this change.']);
            }
            throw $e;
        }

        return redirect('/admin/manage/'.$resource)->with('success', 'Record saved.');
    }

    public function delete(Request $r, string $resource, int $id)
    {
        [$model] = Catalog::definition($resource);
        if ($resource === 'users') {
            Gate::authorize('system-administration');
            UserAccounts::archive($r->user(), $id);
            return back()->with('success', 'Account archived. Academic records are preserved.');
        }
        $row = $model::findOrFail($id);
        try {
            $row->delete();
        } catch (QueryException $e) {
            return back()->withErrors(['record' => 'This record has academic history. Remove its unused relationships first, or suspend the user instead.']);
        }

        return back()->with('success', 'Record removed.');
    }

    private function sendInvitation(User $user): void
    {
        $user->notify(new Invitation(Password::broker()->createToken($user)));
    }

    public function invite(Request $r, User $user)
    {
        Gate::authorize('system-administration');
        abort_unless($user->status === 'inactive' && ! $user->email_verified_at, 422);
        try {
            $this->sendInvitation($user);
            AdministrativeAudit::record($r->user(), 'invitation.sent', 'user', $user->id);
        } catch (\Throwable $e) {
            report($e);
            return back()->withErrors(['email' => 'Mail delivery failed. Check SMTP and resend the invitation.']);
        }

        return back()->with('success', 'Invitation sent.');
    }

    public function settings()
    {
        return view('admin.settings', ['threshold' => Setting::where('key', 'late_threshold')->value('value') ?? 15]);
    }

    public function saveSettings(Request $r)
    {
        $r->validate(['late_threshold' => 'required|integer|min:1|max:120']);
        Setting::updateOrCreate(['key' => 'late_threshold'], ['value' => $r->late_threshold]);

        return back()->with('success', 'Default saved. Existing attendance sessions retain their original threshold.');
    }
}
