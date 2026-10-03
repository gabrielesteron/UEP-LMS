<?php

namespace App\Http\Controllers;

use App\Http\Requests\CatalogRequest;
use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\Setting;
use App\Models\Student;
use App\Models\TeacherAssignment;
use App\Models\User;
use App\Notifications\Invitation;
use App\Services\Catalog;
use App\Services\ScheduleConflicts;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AdminController extends Controller
{
    public function index(Request $r, string $resource)
    {
        [$model,$fields] = Catalog::definition($resource);
        $q = $model::query();
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
        $row = $id ? $model::findOrFail($id) : new $model;
        if ($row instanceof User && $row->role === 'admin') {
            abort(403, 'Manage administrator accounts with the console command.');
        }

        $fieldChoices = collect($fields)->map(fn ($type) => Catalog::choices($type))->all();
        $missingRelated = collect($fields)->contains(fn ($type, $field) => ! in_array($type, ['text', 'email', 'date', 'time', 'textarea', 'number']) && empty($fieldChoices[$field]));

        return view('admin.form', compact('resource', 'fields', 'row', 'fieldChoices', 'missingRelated'));
    }

    public function save(CatalogRequest $r, string $resource, ?int $id = null)
    {
        [$model] = Catalog::definition($resource);
        $row = $id ? $model::findOrFail($id) : new $model;
        $data = $r->validated();
        if ($row instanceof User) {
            abort_if($row->role === 'admin', 403);
            if ($id && ($row->student || $row->teacher) && $row->role !== $data['role']) {
                throw ValidationException::withMessages(['role' => 'Remove the academic profile before changing this role.']);
            }
            if (! $id) {
                $data['password'] = Str::random(48);
                $data['status'] = 'inactive';
            }
            if ($id && ! $row->email_verified_at && $data['status'] === 'active') {
                throw ValidationException::withMessages(['status' => 'The user must activate their account using the invitation.']);
            }
            if ($id && $data['email'] !== $row->email) {
                $data['email_verified_at'] = null;
                $data['status'] = 'inactive';
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
        if ($row instanceof User && ! $row->email_verified_at && $row->status === 'inactive') {
            try {
                $this->sendInvitation($row);
            } catch (\Throwable $e) {
                report($e);

                return redirect('/admin/manage/users')->withErrors(['email' => 'Account saved, but mail delivery failed. Check SMTP and resend the invitation.']);
            }
        }

        return redirect('/admin/manage/'.$resource)->with('success', 'Record saved.');
    }

    public function delete(Request $r, string $resource, int $id)
    {
        [$model] = Catalog::definition($resource);
        $row = $model::findOrFail($id);
        if ($row instanceof User) {
            abort_if($row->role === 'admin', 403);
            $row->update(['status' => 'suspended']);
        }
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

    public function invite(User $user)
    {
        abort_unless($user->status === 'inactive' && ! $user->email_verified_at, 422);
        $this->sendInvitation($user);

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
