@php
    $targetYear = $academicYears->firstWhere('id', $draft['academic_year_id'] ?? null);
    $teacherNames = $teachers->keyBy('id');
    $classCount = array_sum(array_map(fn($row) => count($row['blocks']), $draft['assignments']));
    $assignedBlocks = array_unique(array_merge(...array_column($draft['assignments'], 'blocks')));
    $scopedBlocks = collect($blockChoices ?? collect($draft['blocks'])->map(function ($block, $index) use ($draft, $programs, $yearLevels) {
        $program = $programs->firstWhere('id', $block['program_id'] ?? $draft['program_id'] ?? null);
        $level = $yearLevels->firstWhere('id', $block['year_level_id'] ?? $draft['year_level_id'] ?? null);
        return ['index' => $index, 'name' => $block['name'], 'program' => $program?->code ?? 'Program', 'program_name' => $program?->name ?? '', 'year_level' => $level?->name ?? $block['new_name'] ?? 'Year Level', 'level' => $level?->level ?? $block['new_level'] ?? '', 'label' => ($program?->code ?? 'Program').' / '.($level?->name ?? $block['new_name'] ?? 'Year Level').' / Block '.$block['name']];
    }));
    $blockLabels = $scopedBlocks->keyBy('index');
@endphp
<dl class="row"><dt class="col-sm-3">Academic Year</dt><dd class="col-sm-9">{{ $targetYear?->name ?? $draft['academic_year_name'] ?? 'Selected academic year' }}@if(!$targetYear && !empty($draft['academic_year_name'])) <span class="badge text-bg-info">New</span>@endif</dd><dt class="col-sm-3">Semester</dt><dd class="col-sm-9">{{ [1=>'First',2=>'Second',3=>'Summer'][$draft['semester']] }}</dd><dt class="col-sm-3">Student Enrollment</dt><dd class="col-sm-9">{{ $studentCount }} students · {{ $newAccountCount }} new activation invitations</dd><dt class="col-sm-3">Schedules</dt><dd class="col-sm-9">{{ !empty($draft['copy_schedules']) ? 'Copy basic schedules; conflicts will prevent saving' : 'Set later through existing schedule management' }}</dd></dl>
<h3 class="h5">Programs, Year Levels & Blocks</h3>
<div class="row g-3 mb-4">
    @foreach($scopedBlocks->groupBy('program') as $programBlocks)
        <section class="col-md-6"><div class="border rounded p-3 h-100"><h4 class="h6">{{ $programBlocks->first()['program'] }} — {{ $programBlocks->first()['program_name'] }}</h4>
            @foreach($programBlocks->groupBy('level') as $levelBlocks)
                <div class="border-top mt-3 pt-3"><div class="fw-semibold">{{ $levelBlocks->first()['year_level'] }} @if(filled($levelBlocks->first()['level']))<span class="small text-secondary">(Level {{ $levelBlocks->first()['level'] }})</span>@endif</div><div class="mt-2">Blocks: {{ implode(', ', $levelBlocks->pluck('name')->all()) }}</div></div>
            @endforeach
        </div></section>
    @endforeach
</div>
<h3 class="h5">{{ count($draft['subjects']) }} Subjects · {{ $classCount }} Classes</h3>
<div class="table-responsive"><table class="table"><thead><tr><th>Subject</th><th>Units</th><th>Teacher</th><th>Blocks</th></tr></thead><tbody>
    @foreach($draft['assignments'] as $row)
        @php($subject = $draft['subjects'][$row['subject']])
        <tr><td>{{ $subject['code'] }} — {{ $subject['name'] }}<div class="small text-secondary">{{ $subject['id'] ? 'Existing subject reused' : 'New subject' }}</div></td><td>{{ $subject['units'] }}</td><td>{{ $teacherNames->get($row['teacher_id'])?->user?->name }}</td><td>@foreach($row['blocks'] as $blockIndex)<div>{{ $blockLabels->get($blockIndex)['label'] ?? $draft['blocks'][$blockIndex]['name'] }}</div>@endforeach</td></tr>
    @endforeach
</tbody></table></div>
@if(count($assignedBlocks) < count($draft['blocks']))
    <div class="alert alert-warning">Some blocks have no subject assignments. You can finish them through the existing class management pages.</div>
@endif
<div class="alert alert-info">Creating this setup updates selected students' current block and adds class enrollments. All previous enrollments and academic history remain available. New student accounts must activate before signing in.</div>
<form method="post" action="/admin/setup/create" data-confirm="Create this reviewed academic setup?" data-setup-create>
    @csrf
    <input type="hidden" name="draft_token" value="{{ $draft['token'] }}">
    <input type="hidden" name="review_hash" value="{{ $draft['review_hash'] }}">
    <label class="form-check mb-3"><input class="form-check-input" type="checkbox" name="confirm" value="1" required> <span class="form-check-label">I have reviewed the target year, classes and student placements.</span></label>
    <div class="d-flex flex-wrap gap-2"><a class="btn btn-outline-secondary" href="/admin/setup?step=5">Back to Students</a><button class="btn btn-primary">Create Academic Setup</button></div>
</form>
