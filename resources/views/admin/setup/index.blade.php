@extends('layouts.app')
@section('title', 'Set Up School Year')
@section('content')
<div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-4">
    <div><div class="eyebrow">ACADEMIC MANAGEMENT</div><h1>Set Up School Year</h1><p class="text-secondary mb-0">Build the academic structure in one guided workflow. Records are saved only after review.</p></div>
    <form method="post" action="/admin/setup/restart" data-confirm="Clear this unsaved setup draft?">
        @csrf
        <button class="btn btn-outline-secondary">Start Over</button>
    </form>
</div>
<nav class="setup-steps mb-4" aria-label="Setup steps">
    @foreach($steps as $number => $label)
        @if($number <= $draft['completed'] + 1)
            <a href="/admin/setup?step={{ $number }}" class="setup-step {{ $number === $step ? 'active' : '' }}" @if($number === $step) aria-current="step" @endif><span>{{ $number }}</span>{{ $label }}</a>
        @else
            <span class="setup-step text-secondary"><span>{{ $number }}</span>{{ $label }}</span>
        @endif
    @endforeach
</nav>
<div class="card"><div class="card-body">
    <h2 class="h4 mb-3">Step {{ $step }}: {{ $steps[$step] }}</h2>
    @if($step < 6)
        <form method="post" action="/admin/setup/steps/{{ $step }}" enctype="multipart/form-data" data-setup-form>
            @csrf
            <input type="hidden" name="draft_token" value="{{ $draft['token'] }}">
            @include('admin.setup.step-'.$step)
            <div class="d-flex flex-wrap gap-2 border-top pt-4 mt-4">
                @if($step > 1)
                    <a class="btn btn-outline-secondary" href="/admin/setup?step={{ $step - 1 }}">Back</a>
                @endif
                <button class="btn btn-primary" name="action" value="continue">{{ $step === 5 ? 'Review Setup' : 'Save & Continue' }}</button>
            </div>
        </form>
    @else
        @include('admin.setup.review')
    @endif
</div></div>
@if($step === 1)
    <details class="card mt-4" @if(request('duplicate')) open @endif><summary class="card-header">Duplicate Previous Setup</summary><div class="card-body">
        <p class="text-secondary">Choose the source semester. Shared subjects are reused; new blocks and classes get their own IDs. The next steps let you choose a target and review changes.</p>
        <form method="post" action="/admin/setup/duplicate" class="row g-3">
            @csrf
            <input type="hidden" name="draft_token" value="{{ $draft['token'] }}">
            <div class="col-md-6"><label for="source_year" class="form-label">Source Academic Year</label><select id="source_year" name="source[academic_year_id]" class="form-select" required><option value="">Choose year</option>@foreach($academicYears as $year)<option value="{{ $year->id }}">{{ $year->name }}</option>@endforeach</select></div>
            <div class="col-md-6"><label for="source_semester" class="form-label">Source Semester</label><select id="source_semester" name="source[semester]" class="form-select" required><option value="1">First</option><option value="2">Second</option><option value="3">Summer</option></select></div>
            <div class="col-md-6"><label for="source_program" class="form-label">Source Program</label><select id="source_program" name="source[program_id]" class="form-select" required><option value="">Choose program</option>@foreach($programs as $program)<option value="{{ $program->id }}">{{ $program->code }} — {{ $program->name }}</option>@endforeach</select></div>
            <div class="col-md-6"><label for="source_level" class="form-label">Source Year Level</label><select id="source_level" name="source[year_level_id]" class="form-select" required><option value="">Choose year level</option>@foreach($yearLevels as $level)<option value="{{ $level->id }}">{{ $level->name }}</option>@endforeach</select></div>
            <div class="col-12"><label class="form-check"><input class="form-check-input" type="checkbox" name="copy_schedules" value="1" checked> <span class="form-check-label">Copy basic schedules (conflicts are checked before saving)</span></label><label class="form-check"><input class="form-check-input" type="checkbox" name="copy_students" value="1"> <span class="form-check-label">Copy current students into the new setup</span></label></div>
            <div class="col-12"><button class="btn btn-outline-primary">Load Previous Setup</button></div>
        </form>
    </div></details>
@endif
@endsection
@push('scripts')
<script src="{{ asset('js/academic-setup.js') }}" defer></script>
@endpush
