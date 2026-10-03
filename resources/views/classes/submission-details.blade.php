<div class="d-flex flex-wrap gap-2 mb-2"><span class="badge badge-soft">{{ ucfirst($submission->status) }}{{ $submission->is_late?' · Late':'' }}</span>
    @if(config('lms.show_advanced_features'))<span class="small-muted">Version {{ $submission->version }}</span>@endif
    <span class="small-muted">Submitted {{ $submission->submitted_at->format('M j, Y g:i A') }}</span>
</div>
@if($submission->answer)<details class="mb-3" @if($student) open @endif><summary>Submitted answer</summary><div class="content-text mt-2">{{ $submission->answer }}</div></details>@endif
@if($submission->path)<a href="/files/submissions/{{ $submission->id }}">Download {{ $submission->original_name }}</a>@endif
@if($submission->graded_at)<div class="alert alert-success mt-3 mb-0"><strong>{{ $submission->score }} / {{ $assignment->total_points }}</strong> · Graded {{ $submission->graded_at->format('M j, Y') }}<div class="content-text">{{ $submission->feedback }}</div></div>@endif
