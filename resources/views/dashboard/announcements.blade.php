<section class="card mb-4">
    <div class="card-header d-flex justify-content-between gap-2 flex-wrap"><h2 class="mb-0">Latest announcements</h2><a class="small" href="/announcements">View all →</a></div>
    <div class="card-body">
        @forelse($announcements as $announcement)
            <div class="list-line"><div class="small-muted mb-1">{{ $announcement->created_at->format('M j') }} · {{ $announcement->user?->name ?? 'Archived account' }}</div><h3><a href="/announcements">{{ $announcement->title }}</a></h3><p class="mb-0">{{ Str::limit($announcement->body, 160) }}</p></div>
        @empty
            <div class="empty">No announcements yet.</div>
        @endforelse
    </div>
</section>
