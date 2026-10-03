@php
    $levelRows = $structure['year_levels'] ?? [];
    $levelRows = is_array($levelRows) && $levelRows ? array_values($levelRows) : [['year_level_id' => null, 'name' => '', 'level' => '', 'blocks' => [['name' => '']]]];
    $programValue = is_scalar($structure['program_id'] ?? null) ? (string)$structure['program_id'] : '';
@endphp
<section class="border rounded p-3 mb-4" data-structure-program data-program-index="{{ $programIndex }}">
    <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap mb-3">
        <h3 class="mb-0">Program (Course)</h3>
        <button class="btn btn-sm btn-outline-danger" type="button" data-remove-program aria-label="Remove program and its unsaved year levels and blocks">Remove Program</button>
    </div>
    <div class="mb-3">
        <label class="form-label" for="structure_program_{{ $programIndex }}">Program Name (Course)</label>
        <select class="form-select" id="structure_program_{{ $programIndex }}" name="structures[{{ $programIndex }}][program_id]" required>
            <option value="">Choose program</option>
            @foreach($programs as $program)<option value="{{ $program->id }}" @selected($programValue === (string)$program->id)>{{ $program->code }} — {{ $program->name }}</option>@endforeach
        </select>
    </div>
    <input type="hidden" name="structures[{{ $programIndex }}][expected_level_count]" value="{{ count($levelRows) }}" data-expected-levels>
    <div data-year-levels>
        @foreach($levelRows as $levelIndex => $levelRow)
            @include('admin.setup.year-level-row', ['levelRow' => is_array($levelRow) ? $levelRow : ['year_level_id' => null, 'name' => '', 'level' => '', 'blocks' => [['name' => '']]]])
        @endforeach
    </div>
    <button class="btn btn-sm btn-outline-primary" type="button" data-add-year-level>+ Add Year Level</button>
</section>
