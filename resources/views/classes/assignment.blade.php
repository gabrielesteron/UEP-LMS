@extends('layouts.app')
@section('title',$assignment->title)
@section('content')
<a href="/classes/{{ $assignment->teacher_assignment_id }}#assignments">← {{ $assignment->classroom->label }}</a>
<h1 class="mt-3">{{ $assignment->title }}</h1>
<div class="d-flex flex-wrap gap-2 align-items-center mb-3">
    <span class="text-secondary">Due {{ $assignment->due_at->format('M j, Y g:i A') }} · {{ $assignment->total_points }} points</span>
    @if($assignment->due_at->isPast())<span class="badge text-bg-warning">Overdue · Late submissions accepted</span>@endif
    @unless($student)<span class="badge badge-soft">{{ ucfirst($assignment->status) }}</span>@endunless
</div>
<div class="card mb-4"><div class="card-body"><p>{{ $assignment->description }}</p><h2>Instructions</h2><div class="content-text">{{ $assignment->instructions }}</div>@if($assignment->path)<a class="btn btn-outline-primary mt-3" href="/files/assignments/{{ $assignment->id }}">Download attachment</a>@endif</div></div>
@if($student)
<div class="card mb-4"><div class="card-body"><h2>Submit your work</h2>
    <p class="small-muted">{{ $submissions->count()?'Submitting again replaces the work shown here with your latest submission.':'Not submitted yet.' }} Submissions after the deadline are marked late.</p>
    <form method="post" action="/assignments/{{ $assignment->id }}/submit" enctype="multipart/form-data">
        @csrf
        @if($assignment->allow_text)<label class="form-label w-100">Your answer<textarea class="form-control @error('answer') is-invalid @enderror" name="answer" rows="6" maxlength="100000">{{ old('answer') }}</textarea></label>@endif
        @error('answer')<div class="text-danger small">{{ $message }}</div>@enderror
        <label class="form-label w-100 mt-3">Choose File (maximum 20 MB)<input class="form-control @error('attachment') is-invalid @enderror" type="file" name="attachment" accept=".pdf,.doc,.docx,.ppt,.pptx,.xls,.xlsx,.zip,.jpg,.jpeg,.png,.txt" @required(!$assignment->allow_text)></label>
        @error('attachment')<div class="text-danger small">{{ $message }}</div>@enderror
        <button class="btn btn-primary mt-3">Submit Assignment</button>
    </form>
</div></div>
<h2 class="mb-3">Your submission</h2>
@forelse($submissions as $submission)
<div class="card mb-3"><div class="card-body">
    @include('classes.submission-details')
</div></div>
@empty<div class="empty">No submissions yet. Follow the instructions above, then choose a file{{ $assignment->allow_text?' or enter your answer':'' }} and submit your work.</div>@endforelse
@else
@php($teaching=auth()->user()->role==='teacher')
@php($latestSubmissions=$submissions->unique('student_id')->keyBy('student_id'))
<h2 class="mb-3">Student submissions</h2>
@if($teaching)
<p class="small-muted">Grade the latest work for each student below. Leave a score blank to keep that submission ungraded. Scores must be between 0 and {{ $assignment->total_points }}.</p>
<form method="post" action="/assignments/{{ $assignment->id }}/grades">
    @csrf
    @method('PUT')
    <input type="hidden" name="expected_count" value="{{ $enrollments->filter(fn($row) => $latestSubmissions->has($row->student_id))->count() }}">
@endif
@forelse($enrollments as $enrollment)
@php($submission=$latestSubmissions->get($enrollment->student_id))
<div class="card mb-3 bulk-grading-row"><div class="card-body">
    <h3>{{ $enrollment->student->user?->name ?? 'Archived student' }} <span class="small-muted">{{ $enrollment->student->student_number }}</span></h3>
    @if($enrollment->getAttribute('former_enrollment'))<p class="small-muted">Former enrollment · Existing submitted work remains available for review and grading.</p>@endif
    @if($submission)
        @include('classes.submission-details')
        @if($teaching)
        @php($key='grades.'.$submission->id)
        @php($legacyEditing=(string)old('submission_id')===(string)$submission->id)
        <div class="row g-3 mt-2">
            <div class="col-6 col-md-3"><label class="form-label w-100">Score / {{ $assignment->total_points }}<input class="form-control @error($key.'.score') is-invalid @enderror" name="grades[{{ $submission->id }}][score]" type="number" step="0.01" min="0" max="{{ $assignment->total_points }}" value="{{ old($key.'.score', $legacyEditing ? old('score') : $submission->score) }}"></label>@error($key.'.score')<div class="text-danger small">{{ $message }}</div>@enderror</div>
            <div class="col-6 col-md-3"><label class="form-label w-100">Status<select class="form-select @error($key.'.status') is-invalid @enderror" name="grades[{{ $submission->id }}][status]">
                <option value="graded" @selected(old($key.'.status',$legacyEditing ? old('status') : $submission->status)!=='returned')>Graded</option>
                <option value="returned" @selected(old($key.'.status',$legacyEditing ? old('status') : $submission->status)==='returned')>Returned for revision</option>
            </select></label>@error($key.'.status')<div class="text-danger small">{{ $message }}</div>@enderror</div>
            <div class="col-md-6"><label class="form-label w-100">Feedback<textarea class="form-control @error($key.'.feedback') is-invalid @enderror" name="grades[{{ $submission->id }}][feedback]" rows="2" maxlength="10000">{{ old($key.'.feedback', $legacyEditing ? old('feedback') : $submission->feedback) }}</textarea></label>@error($key.'.feedback')<div class="text-danger small">{{ $message }}</div>@enderror</div>
        </div>
        @endif
    @else<p class="text-secondary mb-0">No submission yet. A score can be entered after this student submits work.</p>@endif
</div></div>
@empty<div class="empty">No students enrolled in this class. Ask an administrator to complete enrollment.</div>@endforelse
@if($teaching)
    <button class="btn btn-primary" @disabled($latestSubmissions->isEmpty())>Save All Grades</button>
</form>
@endif
@if(config('lms.show_advanced_features'))
<details class="mt-4"><summary>Submission history</summary>
    @foreach($submissions as $submission)<div class="card mt-3"><div class="card-body"><h3>{{ $submission->student->user?->name ?? 'Archived student' }}</h3>
        @include('classes.submission-details')
    </div></div>@endforeach
</details>
@endif
@endif
@endsection
