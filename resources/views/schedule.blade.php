@extends('layouts.app')
@section('title','Class schedule')
@section('content')<h1 class="mb-4">Weekly class schedule</h1><div class="card"><div class="card-body">@include('schedule-table')</div></div>@endsection
