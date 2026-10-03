<p class="text-secondary">Choose the academic year and semester. Add programs, their year levels and blocks together in the next step.</p>
@if(!empty($draft['source']))
    <div class="alert alert-info">Previous setup loaded. Choose a target year/semester. Student placement changes preserve earlier enrollments and academic history.</div>
    <label class="form-check mb-3"><input class="form-check-input" type="checkbox" name="copy_schedules" value="1" @checked($draft['copy_schedules'] ?? false)> <span class="form-check-label">Copy basic schedules</span></label>
@endif
<div class="row g-3">
    <div class="col-md-6"><label for="academic_year_id" class="form-label">Existing Academic Year</label><select class="form-select" id="academic_year_id" name="academic_year_id" data-existing-year><option value="">Create a new academic year</option>@foreach($academicYears as $year)<option value="{{ $year->id }}" @selected((string)old('academic_year_id', $draft['academic_year_id'] ?? '') === (string)$year->id)>{{ $year->name }}</option>@endforeach</select></div>
    <div class="col-md-6"><label for="semester" class="form-label">Semester</label><select class="form-select" id="semester" name="semester" required>@foreach([1=>'First',2=>'Second',3=>'Summer'] as $value=>$label)<option value="{{ $value }}" @selected((int)old('semester', $draft['semester'] ?? 1) === $value)>{{ $label }}</option>@endforeach</select></div>
    <div class="col-12" data-new-year><div class="row g-3">
        <div class="col-md-6"><label for="academic_year_name" class="form-label">New Academic Year Name</label><input class="form-control" id="academic_year_name" name="academic_year_name" value="{{ old('academic_year_name', $draft['academic_year_name'] ?? '') }}" maxlength="120" placeholder="2026–2027" data-new-year-required></div>
        <div class="col-md-3"><label for="starts_on" class="form-label">Starts On</label><input class="form-control" type="date" id="starts_on" name="starts_on" value="{{ old('starts_on', $draft['starts_on'] ?? '') }}" data-new-year-required></div>
        <div class="col-md-3"><label for="ends_on" class="form-label">Ends On</label><input class="form-control" type="date" id="ends_on" name="ends_on" value="{{ old('ends_on', $draft['ends_on'] ?? '') }}" data-new-year-required></div>
    </div></div>
</div>
