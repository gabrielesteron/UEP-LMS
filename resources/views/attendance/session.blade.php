@extends('layouts.app')
@section('title','Record attendance')
@section('content')
<a href="/classes/{{ $session->teacher_assignment_id }}#monitoring">← {{ $session->classroom->label }}</a>
<h1 class="mt-3">Attendance · {{ $session->date->format('M j, Y') }}</h1>
<p class="text-secondary">{{ $session->start_time }}–{{ $session->end_time }} · Late threshold: {{ $session->late_threshold }} minutes. Teachers may edit within seven days. Admin changes require a reason.</p>
@php($editable=auth()->user()->role==='admin' || !$session->date->lt(today()->subDays(7)))
@if(!$editable)<div class="alert alert-info">This session is read only. Attendance older than seven days requires an administrator correction.</div>@endif
@if($enrollments->isNotEmpty())
<form method="post" action="/attendance/sessions/{{ $session->id }}/bulk-records" data-bulk-attendance data-late-threshold="{{ $session->late_threshold }}">
    @csrf
    @method('PUT')
    <input type="hidden" name="expected_count" value="{{ $enrollments->count() }}">
    @if($editable)<div class="d-flex gap-2 flex-wrap align-items-center mb-4">
        <button type="button" class="btn btn-outline-primary" data-mark-all-present>Mark All Present</button>
        <span class="small-muted">Change individual statuses, then save the class once.</span>
    </div>@endif
    @foreach($enrollments as $enrollment)
    @php($record=$recordsByStudent->get($enrollment->student_id))
    @php($key='records.'.$enrollment->student_id)
    <div class="card mb-3 bulk-attendance-row"><div class="card-body">
        <h3>{{ $enrollment->student->user?->name ?? 'Archived student' }} <span class="small-muted">{{ $enrollment->student->student_number }}</span></h3>
        <fieldset @disabled(!$editable)>
            <input type="hidden" name="records[{{ $enrollment->student_id }}][student_id]" value="{{ $enrollment->student_id }}">
            <div class="row g-3">
                <div class="col-6 col-md-3"><label class="form-label w-100">Status
                    <select class="form-select @error($key.'.status') is-invalid @enderror" name="records[{{ $enrollment->student_id }}][status]" data-attendance-status required>
                        <option value="">Not recorded</option>
                        @foreach(['present','late','absent','excused'] as $status)<option value="{{ $status }}" @selected(old($key.'.status',$record?->status)===$status)>{{ ucfirst($status) }}</option>@endforeach
                    </select>
                </label>@error($key.'.status')<div class="text-danger small">{{ $message }}</div>@enderror</div>
                <div class="col-6 col-md-3"><label class="form-label w-100">Minutes after start<input class="form-control @error($key.'.minutes_late') is-invalid @enderror" type="number" min="0" max="1440" name="records[{{ $enrollment->student_id }}][minutes_late]" value="{{ old($key.'.minutes_late',$record?->minutes_late) }}" data-attendance-minutes></label>@error($key.'.minutes_late')<div class="text-danger small">{{ $message }}</div>@enderror</div>
                <div class="col-md-6"><label class="form-label w-100">Remarks<input class="form-control @error($key.'.remarks') is-invalid @enderror" name="records[{{ $enrollment->student_id }}][remarks]" maxlength="2000" value="{{ old($key.'.remarks',$record?->remarks) }}"></label>@error($key.'.remarks')<div class="text-danger small">{{ $message }}</div>@enderror</div>
                @if(auth()->user()->role==='admin')
                <div class="col-12"><label class="form-label w-100">Correction reason (required)<input class="form-control @error($key.'.reason') is-invalid @enderror" name="records[{{ $enrollment->student_id }}][reason]" value="{{ old($key.'.reason') }}" maxlength="2000" required></label>@error($key.'.reason')<div class="text-danger small">{{ $message }}</div>@enderror</div>
                @else
                <div class="col-12"><details><summary>Correction note (optional)</summary><label class="form-label w-100 mt-2">Reason<input class="form-control" name="records[{{ $enrollment->student_id }}][reason]" value="{{ old($key.'.reason') }}" maxlength="2000"></label></details></div>
                @endif
            </div>
        </fieldset>
        @if(config('lms.show_advanced_features') && $record)
        <details class="mt-3"><summary>Audit history ({{ $record->logs->count() }})</summary>
            @foreach($record->logs->sortByDesc('id') as $log)<div class="list-line small"><strong>{{ $log->created_at }} · {{ $log->user?->name }}</strong><br>{{ $log->before['status']??'Unrecorded' }} → {{ $log->after['status'] }} · {{ $log->reason }}<div class="small-muted">Before: {{ json_encode($log->before) }}<br>After: {{ json_encode($log->after) }}</div></div>@endforeach
        </details>
        @endif
    </div></div>
    @endforeach
    @if($editable)<button class="btn btn-primary" type="submit">Save Attendance</button>@endif
</form>
@if(config('lms.show_advanced_features'))
    @foreach($recordsByStudent as $record)
        @if($record->excuse)
        <div class="alert alert-info mt-3"><strong>{{ $record->student->user?->name ?? 'Archived student' }} · Excuse: {{ ucfirst($record->excuse->status) }}</strong><p>{{ $record->excuse->reason }}</p>
            @if($record->excuse->status==='pending' && $editable)
            <form method="post" action="/excuses/{{ $record->excuse->id }}" class="d-flex gap-2 flex-wrap">
                @csrf
                @method('PUT')
                <select class="form-select w-auto" name="status"><option value="approved">Approve → Excused</option><option value="rejected">Reject → Absent</option></select><input class="form-control w-auto" name="review_note" placeholder="Review reason" aria-label="Review reason" required><button class="btn btn-primary">Save review</button>
            </form>
            @else<p class="mb-0">{{ $record->excuse->review_note }}</p>@endif
        </div>
        @endif
    @endforeach
@endif
@else<div class="empty">No students enrolled in this class. Ask an administrator to complete enrollment before taking attendance.</div>@endif
@endsection
@push('scripts')
<script src="{{ asset('js/teacher-workflows.js') }}" defer></script>
@endpush
