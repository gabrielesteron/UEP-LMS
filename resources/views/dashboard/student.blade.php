<div class="row g-4">
    <div class="col-xl-8">
        <section class="card mb-4" aria-labelledby="student-attention-heading">
            <div class="card-header d-flex justify-content-between gap-2 flex-wrap"><h2 id="student-attention-heading" class="mb-0">Needs Attention</h2><a class="small" href="/classes?module=learning">View assignments →</a></div>
            <div class="card-body">
                @forelse($studentAttention as $assignment)
                    @php
                        $returned = $assignment->latest_submission_status === 'returned';
                        $overdue = $assignment->due_at->isPast();
                        $dueSoon = ! $overdue && $assignment->due_at->lte(now()->addDays(7));
                    @endphp
                    <div class="list-line">
                        <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap">
                            <div><div class="small-muted mb-1">{{ $assignment->classroom->subject->code }}</div><h3 class="mb-1"><a href="/assignments/{{ $assignment->id }}">{{ $assignment->title }}</a></h3></div>
                            <span class="badge {{ $overdue ? 'text-bg-danger' : ($returned ? 'text-bg-warning' : 'badge-soft') }}">{{ $returned ? 'Needs revision' : ($overdue ? 'Overdue' : ($dueSoon ? 'Due soon' : 'Not submitted')) }}</span>
                        </div>
                        <div class="small-muted">Due {{ $assignment->due_at->format('M j, g:i A') }}@if($returned && $overdue) · Overdue @elseif(!$returned) · Not submitted @endif</div>
                        <a class="btn btn-sm btn-outline-primary mt-2" href="/assignments/{{ $assignment->id }}">{{ $returned ? 'Review feedback & resubmit' : 'Open assignment' }}</a>
                    </div>
                @empty
                    <div class="empty">{{ $classCount ? 'You are up to date. No assignments need a submission right now.' : 'No assignments available. Your administrator will assign your classes.' }}</div>
                @endforelse
                @if($studentAttention->count() === 6)<p class="small-muted mt-3 mb-0">Showing the six nearest deadlines. Open your classes to see every assignment.</p>@endif
            </div>
        </section>
        <section class="card mb-4" aria-labelledby="recent-activity-heading">
            <div class="card-header"><h2 id="recent-activity-heading" class="mb-0">Recent Activity</h2></div>
            <div class="card-body">
                @forelse($recentActivity as $activity)
                    <div class="list-line"><div class="small-muted mb-1">{{ $activity['label'] }} · {{ $activity['subject'] }} · {{ $activity['date']->format('M j') }}</div><h3 class="mb-1"><a href="{{ $activity['url'] }}">{{ $activity['title'] }}</a></h3>@if($activity['score'] !== null)<div class="fw-semibold">{{ $activity['score'] }} points</div>@endif</div>
                @empty
                    <div class="empty">No class activity yet. Published lessons, materials, assignments and your recent grades will appear here.</div>
                @endforelse
            </div>
        </section>
        @include('dashboard.classes')
    </div>
    <div class="col-xl-4">
        <section class="card mb-4" aria-labelledby="my-progress-heading">
            <div class="card-header"><h2 id="my-progress-heading" class="mb-0">My Progress</h2></div>
            <div class="card-body">
                <div class="list-line d-flex justify-content-between gap-2"><a href="/reports/attendance">Attendance</a><strong>{{ $rate === null ? 'N/A' : $rate.'%' }}</strong></div>
                <div class="list-line d-flex justify-content-between gap-2"><a href="/reports/grades">Recorded grade average</a><strong>{{ $gradeAverage === null ? 'N/A' : $gradeAverage.'%' }}</strong></div>
                @if($rate === null)<p class="small-muted mt-3">No attendance records yet.</p>@endif
                @if($gradeAverage === null)<p class="small-muted mt-3">No grades have been recorded.</p>@endif
                <p class="small-muted mt-3 mb-0">Attendance excludes excused absences. Grade average uses your recorded points; missing work is shown in each class gradebook.</p>
                <a class="btn btn-outline-secondary w-100 mt-3" href="/schedule">View My Schedule</a>
            </div>
        </section>
        @include('dashboard.announcements')
        @if(config('lms.show_advanced_features') && $quizzes->isNotEmpty())
            <section class="card"><div class="card-body"><h2>Upcoming quizzes</h2>@foreach($quizzes as $quiz)<div class="list-line"><a href="/quizzes/{{ $quiz->id }}">{{ $quiz->title }}</a><div class="small-muted">Closes {{ $quiz->available_until->format('M j, g:i A') }}</div></div>@endforeach</div></section>
        @endif
    </div>
</div>
