<div class="row g-2 mb-3 align-items-end" data-repeat-row>
    <div class="col"><label class="form-label" for="block_{{ $rowIndex }}">Block Name</label><input class="form-control" id="block_{{ $rowIndex }}" name="blocks[{{ $rowIndex }}][name]" value="{{ $blockName }}" required maxlength="120" placeholder="3J"></div>
    <div class="col-auto"><button class="btn btn-outline-danger" type="button" data-remove aria-label="Remove block">Remove</button></div>
</div>
