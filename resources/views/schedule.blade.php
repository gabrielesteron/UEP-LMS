@extends('layouts.app')
@section('title','Class schedule')
@section('content')
<div class="d-flex justify-content-between align-items-start gap-3 flex-wrap mb-4">
    <div><h1>Weekly class schedule</h1><p class="text-secondary mb-0">Your classes by day, with times, teachers and rooms.</p></div>
    @if(auth()->user()->role === 'admin')<a href="/admin/manage/schedules" class="btn btn-outline-primary">Manage Schedules</a>@endif
</div>
<div class="card"><div class="card-body">@include('schedule-table')</div></div>
@endsection
