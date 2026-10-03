@extends('layouts.app')
@section('title',$classroom->label)
@section('content')
@php($teaching = auth()->user()->role === 'teacher')
<section id="overview" class="hero">
    <div class="eyebrow">{{ $classroom->block->program->name }} / Block {{ $classroom->block->name }}</div>
    <h1>{{ $classroom->subject->code }} · {{ $classroom->subject->name }}</h1>
    <p class="mb-0">{{ $classroom->teacher->user?->name ?? 'Teacher unavailable' }} · {{ $classroom->block->academicYear->name }} · Semester {{ $classroom->block->semester }}</p>
    <p class="mt-3 mb-0 text-secondary">{{ $classroom->subject->description }}</p>
</section>
<nav class="section-nav class-workspace-tabs" aria-label="Class dashboard">
    <a href="#overview">Overview</a><a href="#learning">Learning</a><a href="#monitoring">Monitoring</a>
    @unless($student)<a href="#students">Enrolled Students</a>@endunless
    <a href="/announcements">Announcements</a>
</nav>
@if($teaching)
<div class="class-quick-actions d-flex flex-wrap gap-2 mb-4" aria-label="Class quick actions">
    <a class="btn btn-primary" href="/classes/{{ $classroom->id }}/content/lessons/create">+ Add Lesson</a>
    <a class="btn btn-outline-primary" href="/classes/{{ $classroom->id }}/content/materials/create">+ Add Material</a>
    <a class="btn btn-outline-primary" href="/classes/{{ $classroom->id }}/content/assignments/create">+ Create Assignment</a>
    <a class="btn btn-outline-primary" href="#take-attendance">Take Attendance</a>
    <a class="btn btn-outline-primary" href="#assignments">Enter Grades</a>
</div>
@endif
<form method="get" class="d-flex gap-2 mb-4"><input name="q" class="form-control" value="{{ request('q') }}" maxlength="100" placeholder="Search class content{{ $student?'':' and students' }}" aria-label="Search class"><button class="btn btn-outline-secondary">Search</button></form>
<h2 id="learning" class="mb-3">Learning & Activities</h2>
<nav class="section-nav" aria-label="Learning sections">
    @foreach(array_keys($content) as $section)<a href="#{{ $section }}">{{ ucfirst($section) }}</a>@endforeach
    @if($teaching)<span class="small-muted align-self-center">Open an assignment to grade all submissions.</span>@endif
</nav>
@foreach($content as $kind=>$items)
<section id="{{ $kind }}" class="card mb-4"><div class="card-header d-flex justify-content-between flex-wrap gap-2"><h2 class="mb-0">{{ ucfirst($kind) }}</h2>@if($teaching && $kind==='quizzes')<a class="btn btn-sm btn-primary" href="/classes/{{ $classroom->id }}/content/quizzes/create">Add quiz</a>@endif</div><div class="card-body">
    @forelse($items as $item)
    <article class="list-line"><div class="d-flex justify-content-between gap-3 flex-wrap">
        <div><h3>{{ $item->title }}</h3><p class="text-secondary mb-2">{{ $item->description }}</p>@unless($student)<span class="badge badge-soft">{{ ucfirst($item->status) }}</span>@endunless</div>
        <div class="d-flex gap-2 flex-wrap align-items-start">
            @if($kind==='materials')
                @if($item->path)<a class="btn btn-sm btn-outline-primary" href="/files/materials/{{ $item->id }}">Download file</a>@else<span class="small-muted">No file attached</span>@endif
            @elseif(in_array($kind,['assignments','quizzes']))
                <a class="btn btn-sm btn-primary" href="/{{ $kind }}/{{ $item->id }}">{{ $teaching && $kind==='assignments' ? 'Submissions & Grades' : 'Open '.Str::singular($kind) }}</a>
            @endif
            @if($teaching)
            <a class="btn btn-sm btn-outline-secondary" href="/classes/{{ $classroom->id }}/content/{{ $kind }}/{{ $item->id }}/edit">Edit</a>
            <form method="post" action="/classes/{{ $classroom->id }}/content/{{ $kind }}/{{ $item->id }}" data-confirm="Delete this content? Items with student work are protected.">
                @csrf
                @method('DELETE')
                <button class="btn btn-sm btn-outline-danger">Delete</button>
            </form>
            @endif
        </div>
    </div>
    @if($kind==='lessons')<details class="mt-3"><summary>Read lesson</summary><div class="content-text mt-3">{{ $item->content }}</div></details>
    @elseif($kind==='assignments')<div class="small-muted mt-2">Due {{ $item->due_at->format('M j, Y g:i A') }} · {{ $item->total_points }} points</div>
    @elseif($kind==='quizzes')<div class="small-muted mt-2">{{ $item->time_limit }} minutes · Available {{ $item->available_from->format('M j, g:i A') }} – {{ $item->available_until->format('M j, g:i A') }}</div>@endif
    </article>
    @empty<div class="empty">No {{ $kind }} available yet. @if($teaching)Use the class quick actions to add learning content.@endif</div>@endforelse
</div></section>
@endforeach
<h2 id="monitoring" class="mb-3">Student Monitoring</h2>
<div class="d-flex flex-wrap gap-2 mb-3">
    <a class="btn btn-outline-primary" href="/classes/{{ $classroom->id }}/gradebook">View Grades</a>
    <a class="btn btn-outline-primary" href="/reports/attendance?class_id={{ $classroom->id }}">View Attendance</a>
    @if($student)<a class="btn btn-outline-secondary" href="/schedule">My Schedule</a>@endif
</div>
<section id="attendance" class="card mb-4"><div class="card-header"><h2 class="mb-0">Attendance</h2></div><div class="card-body">
    <a href="/reports/attendance?class_id={{ $classroom->id }}" class="btn btn-outline-primary mb-3">View attendance report & calendar</a>
    @unless($student)
    @if($teaching)
    <form id="take-attendance" method="post" action="/classes/{{ $classroom->id }}/attendance" class="row g-3 mb-4">
        @csrf
        <div class="col-md-3"><label class="form-label w-100">Session date<input class="form-control @error('date') is-invalid @enderror" name="date" type="date" value="{{ old('date', today()->format('Y-m-d')) }}" min="{{ today()->subDays(7)->format('Y-m-d') }}" max="{{ today()->format('Y-m-d') }}" required></label>@error('date')<div class="text-danger small">{{ $message }}</div>@enderror</div>
        <div class="col-md-3"><label class="form-label w-100">Start time<input class="form-control @error('start_time') is-invalid @enderror" name="start_time" type="time" value="{{ old('start_time') }}" required></label>@error('start_time')<div class="text-danger small">{{ $message }}</div>@enderror</div>
        <div class="col-md-3"><label class="form-label w-100">End time<input class="form-control @error('end_time') is-invalid @enderror" name="end_time" type="time" value="{{ old('end_time') }}" required></label>@error('end_time')<div class="text-danger small">{{ $message }}</div>@enderror</div>
        <div class="col-md-3 align-self-end"><button class="btn btn-primary mb-2">Create session</button></div>
    </form>
    @endif
    <div class="table-responsive"><table class="table"><thead><tr><th>Date</th><th>Time</th><th>Action</th></tr></thead><tbody>
        @forelse($sessions as $session)<tr><td>{{ $session->date->format('M j, Y') }}</td><td>{{ $session->start_time }}–{{ $session->end_time }}</td><td>
            @if($teaching && !$session->date->lt(today()->subDays(7)))<a href="/attendance/sessions/{{ $session->id }}">Record attendance →</a>
            @else<a href="/reports/attendance?class_id={{ $classroom->id }}&amp;from={{ $session->date->format('Y-m-d') }}&amp;to={{ $session->date->format('Y-m-d') }}">View records →</a>@endif
        </td></tr>@empty<tr><td colspan="3" class="text-secondary">No attendance sessions yet.</td></tr>@endforelse
    </tbody></table></div>
    @endunless
</div></section>
@unless($student)
<section id="students" class="card"><div class="card-header d-flex justify-content-between flex-wrap gap-2"><h2 class="mb-0">Class roster</h2>@if(config('lms.show_advanced_features'))<a href="/reports/students?class_id={{ $classroom->id }}">Export roster →</a>@endif</div>
<div class="card-body"><div class="table-responsive"><table class="table"><thead><tr><th>Student number</th><th>Name</th><th>Email</th></tr></thead><tbody>
    @forelse($students as $person)<tr><td>{{ $person->student_number }}</td><td>{{ $person->user->name }}</td><td>{{ $person->user->email }}</td></tr>
    @empty<tr><td colspan="3">No students found. @if(auth()->user()->role==='admin')<a href="/admin/manage/enrollments/create">Enroll a student</a>@else Ask an administrator to enroll students in this class.@endif</td></tr>@endforelse
</tbody></table></div></div></section>
@endunless
@endsection
