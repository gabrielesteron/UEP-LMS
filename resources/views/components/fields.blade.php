@foreach($fields as $field=>$type)
@php
    $value = old($field, $row->$field ?? '');
    $choices = $fieldChoices[$field] ?? \App\Services\Catalog::choices($type);
    $label = \App\Services\Catalog::fieldLabel($field, $resource ?? null);
    $invalid = $errors->has($field) ? ' is-invalid' : '';
    $decimal = in_array($field, ['total_points', 'points']) || (($resource ?? '') === 'subjects' && in_array($field, ['units', 'lecture_units', 'laboratory_units']));
    $optional = ($resource ?? '') === 'subjects' && in_array($field, ['program_id', 'year_level_id', 'semester', 'lecture_units', 'laboratory_units']);
    $selfAccount = ($resource ?? '') === 'users' && $row->exists && $row->id === auth()->id() && in_array($field, ['role', 'status', 'email']);
    $value = $selfAccount ? $row->$field : $value;
    $locked = $selfAccount || ($resource ?? '') === 'subjects' && $row->program_id && in_array($field, ['code', 'program_id', 'year_level_id', 'semester']);
@endphp
<div class="mb-3">
    <label class="form-label" for="field_{{ $field }}">{{ $label }} @if($field==='day')<span class="text-secondary">(1 = Monday, 7 = Sunday)</span>@endif</label>
    @if($type==='textarea')
        <textarea class="form-control{{ $invalid }}" id="field_{{ $field }}" name="{{ $field }}" rows="{{ in_array($field,['content','instructions'])?8:3 }}" @required(in_array($field,['content','instructions']))>{{ $value }}</textarea>
    @elseif($choices || !in_array($type,['text','email','date','time','number','file','datetime-local']))
        <select class="form-select{{ $invalid }}" id="field_{{ $field }}" name="{{ $field }}" @required(!$optional) @disabled($locked)>
            <option value="">{{ $optional ? 'Not specified (shared catalog)' : 'Select '.$label }}</option>
            @foreach($choices as $key=>$choice)<option value="{{ $key }}" @selected((string)$value===(string)$key)>{{ $field==='role' ? (\App\Models\User::ROLES[$key] ?? $choice) : ($field==='allow_text' ? ($key ? 'Yes' : 'No') : $choice) }}</option>@endforeach
        </select>
        @if($locked)<input type="hidden" name="{{ $field }}" value="{{ $row->$field }}">@endif
        @if(!$choices)<div class="form-text">No {{ strtolower($label) }} options available. Ask an administrator to create the required records first.</div>@endif
    @elseif($type==='file')
        <input class="form-control{{ $invalid }}" type="file" id="field_{{ $field }}" name="{{ $field }}" accept=".pdf,.doc,.docx,.ppt,.pptx,.xls,.xlsx,.zip,.jpg,.jpeg,.png,.txt" @required(($kind ?? '')==='materials' && !$row->exists)>
        <div class="form-text">PDF, Office documents, ZIP, images or TXT. Maximum 20 MB. @if($row->path)A file is already attached; leave blank to keep it.@endif</div>
    @else
        @php($formatted=$value instanceof \Carbon\CarbonInterface ? $value->format($type==='date'?'Y-m-d':'Y-m-d\TH:i') : ($type==='time'?substr((string)$value,0,5):$value))
        <input class="form-control{{ $invalid }}" type="{{ $type }}" id="field_{{ $field }}" name="{{ $field }}" value="{{ $formatted }}" @if($type==='number') min="{{ in_array($field,['lecture_units','laboratory_units']) ? '0' : ($decimal ? '0.01' : '1') }}" step="{{ $decimal ? '0.01' : '1' }}" @if(in_array($field,['level','units','lecture_units','laboratory_units'])) max="12" @endif @endif @required(!$optional) @readonly($locked)>
        @if($field==='level')<div class="form-text">Whole number from 1 to 12, for example 1, 2, 3 or 4.</div>@endif
    @endif
    @error($field)<div class="invalid-feedback">{{ $message }}</div>@enderror
</div>
@endforeach
