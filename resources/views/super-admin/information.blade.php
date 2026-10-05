@extends('layouts.app')
@section('title','System Information')
@section('content')
<h1 class="mb-4">System Information</h1><div class="card" style="max-width:760px"><div class="card-body"><dl class="row mb-0">@foreach($information as $label => $value)<dt class="col-sm-4">{{ $label }}</dt><dd class="col-sm-8">{{ $value }}</dd>@endforeach</dl></div></div>
@endsection
