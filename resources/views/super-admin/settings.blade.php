@extends('layouts.app')
@section('title','System Settings')
@section('content')
<h1 class="mb-4">System Settings</h1>
<div class="card" style="max-width:760px"><div class="card-body"><p class="small-muted">Portal branding only. Academic setup and attendance configuration retain their existing workflows.</p>
<form method="post" action="/super-admin/settings">@csrf @method('PUT')
@foreach(['lms_name' => 'LMS Name', 'institution_name' => 'Institution Name'] as $key => $label)
<label class="form-label w-100 mb-3">{{ $label }}<input class="form-control" name="{{ $key }}" value="{{ old($key, config('lms.'.$key)) }}" required maxlength="120"></label>
@endforeach
<button class="btn btn-primary">Save Settings</button></form></div></div>
@endsection
