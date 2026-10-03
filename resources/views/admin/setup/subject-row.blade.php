<div class="border rounded p-3 mb-3" data-repeat-row>
    <div class="row g-3">
        <div class="col-12"><label class="form-label" for="catalog_{{ $rowIndex }}">Existing Subject (optional)</label><select id="catalog_{{ $rowIndex }}" class="form-select" name="subjects[{{ $rowIndex }}][id]" data-subject-choice><option value="">Enter subject details below</option>@foreach($subjectChoices as $choice)<option value="{{ $choice->id }}" data-code="{{ $choice->code }}" data-name="{{ $choice->name }}" data-units="{{ $choice->units }}" @selected((string)$subject['id'] === (string)$choice->id)>{{ $choice->code }} — {{ $choice->name }}</option>@endforeach</select></div>
        <div class="col-sm-3"><label class="form-label" for="subject_code_{{ $rowIndex }}">Subject Code</label><input id="subject_code_{{ $rowIndex }}" class="form-control" name="subjects[{{ $rowIndex }}][code]" value="{{ $subject['code'] }}" required maxlength="120" data-subject-code></div>
        <div class="col-sm-6"><label class="form-label" for="subject_name_{{ $rowIndex }}">Subject Name</label><input id="subject_name_{{ $rowIndex }}" class="form-control" name="subjects[{{ $rowIndex }}][name]" value="{{ $subject['name'] }}" required maxlength="120" data-subject-name></div>
        <div class="col-sm-3"><label class="form-label" for="subject_units_{{ $rowIndex }}">Units</label><input id="subject_units_{{ $rowIndex }}" class="form-control" type="number" min="1" max="12" step="1" name="subjects[{{ $rowIndex }}][units]" value="{{ $subject['units'] }}" required data-subject-units></div>
        <div class="col-12"><button class="btn btn-sm btn-outline-danger" type="button" data-remove>Remove Subject</button></div>
    </div>
</div>
