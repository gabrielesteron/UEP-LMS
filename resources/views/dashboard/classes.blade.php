<section class="mb-4" aria-labelledby="dashboard-classes-heading">
    <div class="d-flex justify-content-between align-items-center gap-2 flex-wrap mb-3">
        <h2 id="dashboard-classes-heading" class="mb-0">{{ $user->role === 'admin' ? 'Classes' : 'My Classes' }} <span class="small-muted">({{ $classCount }})</span></h2>
        <a href="/classes" class="small">View all classes →</a>
    </div>
    <div class="row g-3">
        @forelse($classes as $classroom)
            <div class="col-md-6">@include('classes.card')</div>
        @empty
            <div class="col-12"><div class="empty">No classes have been assigned yet.@if($user->role === 'admin')<div class="mt-3"><a class="btn btn-primary btn-sm" href="/admin/setup">+ Set Up School Year</a></div>@else<div class="small-muted mt-2">Your administrator will assign your classes.</div>@endif</div></div>
        @endforelse
    </div>
</section>
