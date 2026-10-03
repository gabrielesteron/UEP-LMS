@php($blockValue = is_scalar($blockName) ? (string)$blockName : '')
<div class="row g-2 mb-3 align-items-end" data-structure-block data-block-index="{{ $blockIndex }}">
    <div class="col"><label class="form-label" for="structure_block_{{ $programIndex }}_{{ $levelIndex }}_{{ $blockIndex }}">Block Name</label><input class="form-control" id="structure_block_{{ $programIndex }}_{{ $levelIndex }}_{{ $blockIndex }}" name="structures[{{ $programIndex }}][year_levels][{{ $levelIndex }}][blocks][{{ $blockIndex }}][name]" value="{{ $blockValue }}" required maxlength="120" placeholder="A"></div>
    <div class="col-auto"><button class="btn btn-sm btn-outline-danger" type="button" data-remove-block aria-label="Remove block">Remove Block</button></div>
</div>
