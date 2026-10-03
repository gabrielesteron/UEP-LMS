@php($days = ['', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'])
<div class="row g-3" data-weekly-schedule>
    @forelse($schedules->groupBy('day') as $day => $dailySchedules)
        <section class="col-md-6 col-xl-4" aria-label="{{ $days[$day] }} classes">
            <div class="border rounded h-100 p-3">
                <h3 class="d-flex justify-content-between gap-2 mb-3">{{ $days[$day] }}<span class="badge badge-soft">{{ $dailySchedules->count() }}</span></h3>
                @foreach($dailySchedules as $schedule)
                    <div class="list-line pt-2">
                        <div class="fw-semibold mb-2"><time>{{ \Carbon\Carbon::parse($schedule->start_time)->format('g:i A') }}</time> – <time>{{ \Carbon\Carbon::parse($schedule->end_time)->format('g:i A') }}</time></div>
                        <span class="class-code mb-2">{{ $schedule->classroom->subject->code }}</span>
                        <h3 class="mb-1"><a href="/classes/{{ $schedule->teacher_assignment_id }}">{{ $schedule->classroom->subject->name }}</a></h3>
                        <div class="small-muted">{{ $schedule->classroom->teacher->user?->name ?? 'Archived teacher' }}</div>
                        <div class="small-muted">{{ $schedule->classroom->block->program->code }} · Block {{ $schedule->classroom->block->name }}</div>
                        <div class="mt-2">Room: {{ filled($schedule->room) ? $schedule->room : 'To be announced' }}</div>
                    </div>
                @endforeach
            </div>
        </section>
    @empty
        <div class="col-12"><div class="empty">No scheduled classes yet.{{ auth()->user()->role === 'student' ? ' Your administrator will publish your class schedule.' : '' }}@if(auth()->user()->role === 'admin')<div class="mt-3"><a class="btn btn-primary btn-sm" href="/admin/manage/schedules/create">Add Schedule</a></div>@endif</div></div>
    @endforelse
</div>
