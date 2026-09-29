@foreach($fields as $field=>$type)
@php($value=old($field,$row->$field ?? ''))
@php($choices=\App\Services\Catalog::choices($type))
<div class="mb-3"><label class="form-label" for="field_{{ $field }}">{{ Str::headline($field) }} @if($field==='day')<span class="text-secondary">(1 = Monday, 7 = Sunday)</span>@endif</label>
@if($type==='textarea')<textarea class="form-control" id="field_{{ $field }}" name="{{ $field }}" rows="{{ in_array($field,['content','instructions'])?8:3 }}">{{ $value }}</textarea>
@elseif($choices || !in_array($type,['text','email','date','time','number','file','datetime-local']))<select class="form-select" id="field_{{ $field }}" name="{{ $field }}" required><option value="">Select {{ Str::headline($field) }}</option>@foreach($choices as $key=>$label)<option value="{{ $key }}" @selected((string)$value===(string)$key)>{{ $label }}</option>@endforeach</select>
@elseif($type==='file')<input class="form-control" type="file" id="field_{{ $field }}" name="{{ $field }}"><div class="form-text">PDF, Office documents, ZIP, images or TXT. Maximum 20 MB. @if($row->path)A file is already attached; leave blank to keep it.@endif</div>
@else
@php($formatted=$value instanceof \Carbon\CarbonInterface ? $value->format($type==='date'?'Y-m-d':'Y-m-d\TH:i') : ($type==='time'?substr((string)$value,0,5):$value))
<input class="form-control" type="{{ $type }}" id="field_{{ $field }}" name="{{ $field }}" value="{{ $formatted }}" @if($type==='number') min="0.01" step="{{ in_array($field,['total_points','points'])?'0.01':'1' }}" @endif required>
@endif</div>
@endforeach
