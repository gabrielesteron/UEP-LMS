@php
    $blockRows = $levelRow['blocks'] ?? [];
    $blockRows = is_array($blockRows) && $blockRows ? array_values($blockRows) : [['name' => '']];
    $levelValue = is_scalar($levelRow['year_level_id'] ?? null) ? (string)$levelRow['year_level_id'] : '';
    $levelNameValue = is_scalar($levelRow['name'] ?? null) ? (string)$levelRow['name'] : '';
    $levelNumberValue = is_scalar($levelRow['level'] ?? null) ? (string)$levelRow['level'] : '';
    $existingLevel = filled($levelValue);
    $levelKey = $programIndex.'_'.$levelIndex;
@endphp
<fieldset class="border rounded p-3 mb-3" data-structure-level data-level-index="{{ $levelIndex }}">
    <legend class="float-none w-auto px-2 h6 mb-0">Year Level</legend>
    <div class="row g-3">
        <div class="col-md-8">
            <label class="form-label" for="structure_level_{{ $levelKey }}">Existing Year Level</label>
            <select class="form-select" id="structure_level_{{ $levelKey }}" name="structures[{{ $programIndex }}][year_levels][{{ $levelIndex }}][year_level_id]" data-year-level-choice>
                <option value="">Create a new year level</option>
                @foreach($yearLevels as $level)<option value="{{ $level->id }}" @selected($levelValue === (string)$level->id)>{{ $level->name }} — Level {{ $level->level }}</option>@endforeach
            </select>
        </div>
        <div class="col-md-4 d-flex align-items-end"><button class="btn btn-sm btn-outline-danger" type="button" data-remove-year-level>Remove Year Level</button></div>
        <div class="col-12" data-new-level-fields @if($existingLevel) hidden @endif>
            <div class="row g-3">
                <div class="col-md-8"><label class="form-label" for="structure_level_name_{{ $levelKey }}">Year Level Name</label><input class="form-control" id="structure_level_name_{{ $levelKey }}" name="structures[{{ $programIndex }}][year_levels][{{ $levelIndex }}][name]" value="{{ $levelNameValue }}" maxlength="120" placeholder="First Year" data-new-level-required @if($existingLevel) disabled @else required @endif></div>
                <div class="col-md-4"><label class="form-label" for="structure_level_number_{{ $levelKey }}">Year Level Number</label><input class="form-control" type="number" id="structure_level_number_{{ $levelKey }}" name="structures[{{ $programIndex }}][year_levels][{{ $levelIndex }}][level]" value="{{ $levelNumberValue }}" min="1" max="12" step="1" placeholder="1" data-new-level-required @if($existingLevel) disabled @else required @endif></div>
            </div>
            <p class="small text-secondary mt-2 mb-0">Use a whole number such as 1, 2, 3 or 4. If that number already exists, its existing catalog year level is reused without renaming it.</p>
        </div>
        <div class="col-12">
            <h4 class="h6">Blocks in this year level</h4>
            <input type="hidden" name="structures[{{ $programIndex }}][year_levels][{{ $levelIndex }}][expected_block_count]" value="{{ count($blockRows) }}" data-expected-blocks>
            <div data-level-blocks>
                @foreach($blockRows as $blockIndex => $block)
                    @include('admin.setup.block-row', ['blockName' => is_array($block) ? ($block['name'] ?? '') : ''])
                @endforeach
            </div>
            <button class="btn btn-sm btn-outline-primary" type="button" data-add-block>+ Add Block</button>
        </div>
    </div>
</fieldset>
