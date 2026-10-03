@extends('layouts.app')
@section('title', 'Overview')
@section('content')
<section class="hero">
    <div class="d-flex justify-content-between gap-3 flex-wrap">
        <div>
            <div class="eyebrow">{{ now()->format('l, F j, Y') }}</div>
            <h1>Welcome back, {{ $user->name }}.</h1>
            <p class="mb-0 text-secondary">{{ $user->role === 'student' ? 'Your next steps, recent class activity, and progress in one place.' : ($user->role === 'teacher' ? 'Start with your classes, teach, and keep track of your students.' : 'Set up classes, manage users, and monitor your academic community.') }}</p>
        </div>
        <div class="align-self-center"><span class="pill">{{ ucfirst($user->role) }} workspace</span></div>
    </div>
    @if($user->role === 'admin')
        <div class="d-flex gap-2 flex-wrap mt-4">
            <a class="btn btn-primary" href="/admin/setup">+ Set Up School Year</a>
            <a class="btn btn-outline-secondary" href="/admin/setup?duplicate=1">Duplicate Previous Setup</a>
        </div>
    @else
        <div class="d-flex gap-2 flex-wrap mt-4">
            <a class="btn btn-primary" href="/classes">My Classes</a>
            @if($user->role === 'student')<a class="btn btn-outline-secondary" href="/schedule">My Schedule</a>@endif
        </div>
    @endif
    @if($user->role === 'student' && $user->student?->block)
        <div class="mt-4 class-meta">{{ $user->student->block->program->name }} / {{ $user->student->block->yearLevel->name }} / Block {{ $user->student->block->name }} / {{ $user->student->block->academicYear->name }}</div>
    @endif
</section>

@if($user->role === 'student')
    @include('dashboard.student')
@else
    @if($needsAttention->isNotEmpty())
        <section class="card mb-4" aria-labelledby="admin-attention-heading">
            <div class="card-header"><h2 id="admin-attention-heading" class="mb-0">Needs Attention</h2></div>
            <div class="card-body">
                <div class="row g-3">
                    @foreach($needsAttention as $item)
                        <div class="col-md-6">
                            <a href="{{ $item['url'] }}" class="d-flex justify-content-between gap-3 border rounded p-3 text-decoration-none h-100">
                                <div><strong>{{ $item['label'] }}</strong><div class="small-muted mt-1">{{ $item['detail'] }}</div></div>
                                @if($item['count'] !== null)<span class="badge badge-soft align-self-start">{{ $item['count'] }}</span>@endif
                            </a>
                        </div>
                    @endforeach
                </div>
            </div>
        </section>
    @endif
    <div class="row g-3 mb-4">
        @foreach($stats as $label => $value)
            <div class="col-6 col-xl-3"><div class="card h-100"><div class="card-body"><div class="stat-label">{{ $label }}</div><div class="stat-value">{{ $value }}</div><div class="stat-accent"></div></div></div></div>
        @endforeach
    </div>
    <div class="row g-4">
        <div class="col-xl-8">
            @include('dashboard.classes')
            @include('dashboard.announcements')
            <section class="card">
                <div class="card-header d-flex justify-content-between gap-2 flex-wrap"><h2 class="mb-0">Schedule preview</h2><a class="small" href="/schedule">View full schedule →</a></div>
                <div class="card-body">@include('schedule-table', ['schedules' => $schedules])</div>
            </section>
        </div>
        <div class="col-xl-4">
            <section class="card mb-4">
                <div class="card-header d-flex justify-content-between gap-2"><h2 class="mb-0">Coming up</h2><span class="badge badge-soft">{{ $upcoming->count() + $quizzes->count() }}</span></div>
                <div class="card-body">
                    @forelse($upcoming as $item)
                        <div class="list-line"><span class="class-code">{{ $item->classroom->subject->code }}</span><h3 class="mt-2"><a href="/assignments/{{ $item->id }}">{{ $item->title }}</a></h3><div class="small-muted">Assignment · Due {{ $item->due_at->format('M j, g:i A') }}</div></div>
                    @empty
                        <p class="small-muted mb-0">No upcoming assignments.</p>
                    @endforelse
                    @if(config('lms.show_advanced_features'))
                        @foreach($quizzes as $quiz)
                            <div class="list-line"><span class="class-code">{{ $quiz->classroom->subject->code }}</span><h3 class="mt-2"><a href="/quizzes/{{ $quiz->id }}">{{ $quiz->title }}</a></h3><div class="small-muted">Quiz · Closes {{ $quiz->available_until->format('M j, g:i A') }}</div></div>
                        @endforeach
                    @endif
                </div>
            </section>
            <section class="card mb-4">
                <div class="card-body">
                    <div class="eyebrow">ACADEMIC SNAPSHOT</div>
                    <h2 class="mt-3">{{ $user->role === 'teacher' ? 'Class progress at a glance.' : 'Institution progress at a glance.' }}</h2>
                    <div class="list-line d-flex justify-content-between gap-2">Attendance <strong>{{ $rate === null ? 'N/A' : $rate.'%' }}</strong></div>
                    <div class="list-line d-flex justify-content-between gap-2">Recorded grade average <strong>{{ $gradeAverage === null ? 'N/A' : $gradeAverage.'%' }}</strong></div>
                    <p class="small-muted mt-3 mb-0">Grade average uses recorded points across accessible classes. See each class gradebook for missing work.</p>
                </div>
            </section>
            @if($recentUsers->isNotEmpty())
                <section class="card"><div class="card-body"><h2>Recent accounts</h2>@foreach($recentUsers as $recent)<div class="list-line"><a href="/admin/manage/users/{{ $recent->id }}/edit">{{ $recent->name }}</a><span class="small-muted d-block">{{ $recent->role }} · {{ $recent->status }}</span></div>@endforeach</div></section>
            @endif
        </div>
    </div>
@endif
@endsection
