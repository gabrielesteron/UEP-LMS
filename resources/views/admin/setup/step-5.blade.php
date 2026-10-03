<div class="alert alert-info">Choose up to 500 students across both options. Each student has one current block; this setup moves their current placement and keeps previous class enrollments, grades, attendance and submissions.</div>
<h3 class="h5">A. Select Existing Students</h3>
<p class="small text-secondary">{{ count($draft['students'] ?? []) }} selected across all pages. Save your selection before changing pages or searching.</p>
<div class="d-flex flex-wrap gap-2 mb-3"><input class="form-control setup-search" id="student_search" aria-label="Search student name or email" placeholder="Search student name or email" value="{{ request('q') }}"><button type="button" class="btn btn-outline-secondary" data-student-search>Search</button><a href="/admin/setup?step=5" class="btn btn-light">Reset</a></div>
@php($placements = collect($draft['students'] ?? [])->keyBy('id'))
<div class="table-responsive"><table class="table align-middle"><thead><tr><th>Select</th><th>Student</th><th>Current Block</th><th>Target Block</th></tr></thead><tbody>
    @forelse($students as $student)
        <tr><td><input type="hidden" name="visible_ids[]" value="{{ $student->id }}"><input class="form-check-input" aria-label="Enroll {{ $student->user->name }}" type="checkbox" name="selected[]" value="{{ $student->id }}" @checked($placements->has($student->id))></td><td><strong>{{ $student->user->name }}</strong><div class="small text-secondary">{{ $student->student_number }} · {{ $student->user->email }}</div></td><td>{{ $student->block?->name ?? 'No block' }}</td><td><select class="form-select" aria-label="Target block for {{ $student->user->name }}" name="placements[{{ $student->id }}]">@foreach($draft['blocks'] as $index=>$block)<option value="{{ $index }}" @selected((int)($placements->get($student->id)['block'] ?? 0) === $index)>{{ $block['name'] }}</option>@endforeach</select></td></tr>
    @empty
        <tr><td colspan="4">No students match this search. Use the CSV option to invite new students.</td></tr>
    @endforelse
</tbody></table></div>
<button class="btn btn-outline-primary mb-3" name="action" value="selection">Save Selection</button>
{{ $students->links() }}
<hr class="my-4">
<h3 class="h5">B. Preview Student CSV</h3>
<p>Required columns: <strong>Student ID, Name, Email, Block</strong>. Block must match a block in this setup. UTF-8 comma-separated CSV, maximum 1 MB / 500 rows. Existing matching accounts are reused.</p>
<p><a href="/admin/setup/template" class="btn btn-sm btn-outline-secondary">Download CSV Template</a></p>
<div class="row g-2 align-items-end"><div class="col-md-8"><label class="form-label" for="csv_file">Student CSV</label><input class="form-control" type="file" name="csv_file" id="csv_file" accept=".csv,text/csv"></div><div class="col-md-4"><button class="btn btn-outline-primary" name="action" value="preview">Preview CSV</button></div></div>
@if(!empty($draft['csv_preview']))
    <div class="d-flex flex-wrap align-items-center gap-2 mt-4 mb-2"><strong>{{ $csv['valid_count'] }} valid · {{ $csv['invalid_count'] }} invalid rows</strong><button class="btn btn-sm btn-outline-danger" name="action" value="clear_csv">Remove CSV</button></div>
    @if(isset($csv['errors'][0]))
        <div class="alert alert-danger">@foreach($csv['errors'][0] as $error)<div>{{ $error }}</div>@endforeach</div>
    @endif
    <div class="table-responsive"><table class="table"><thead><tr><th>Row</th><th>Student</th><th>Email</th><th>Block</th><th>Preview Result</th></tr></thead><tbody>
        @foreach($csvRows as $row)
            <tr><td>{{ $row['row'] }}</td><td>{{ $row['name'] }}<div class="small">{{ $row['student_number'] }}</div></td><td>{{ $row['email'] }}</td><td>{{ $row['block'] }}</td><td>@if($row['valid'])<span class="badge text-bg-success">{{ $row['action'] === 'existing' ? 'Reuse existing account' : 'Invite new student' }}</span>@else<ul class="text-danger mb-0">@foreach($row['errors'] as $error)<li>{{ $error }}</li>@endforeach</ul>@endif</td></tr>
        @endforeach
    </tbody></table></div>
    {{ $csvRows->links() }}
    <p class="small text-secondary">Preview creates no accounts. New accounts are inactive until the student follows the existing activation email and sets their password. Invitations are queued after the confirmed save.</p>
@endif
