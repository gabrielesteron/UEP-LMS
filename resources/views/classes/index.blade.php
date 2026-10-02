@extends('layouts.app')
@php
    $module = in_array(request('module'), ['learning', 'monitoring']) ? request('module') : 'academic';
    $title = match ($module) {
        'learning' => auth()->user()->role === 'admin' ? 'Monitor Class Activities' : 'Lessons, Materials & Assignments',
        'monitoring' => 'Record Attendance & Grades',
        default => auth()->user()->role === 'admin' ? 'Classes' : 'My Classes',
    };
    $classAnchor = match ($module) { 'learning' => '#learning', 'monitoring' => '#attendance', default => '' };
@endphp
@section('title', $title)
@section('content')
<h1>{{ $title }}</h1>
<p class="text-secondary mb-4">{{ $module==='learning' ? 'Choose a class to view its lessons, materials and assignments.' : ($module==='monitoring' ? 'Choose a class to record attendance or open its gradebook.' : 'Classes, subjects and teachers, organized by block.') }}</p>
@forelse($classes->groupBy('block_id') as $group)
    <h2 class="mt-4 mb-3">{{ $group->first()->block->program->code }} · {{ $group->first()->block->yearLevel->name }} · Block {{ $group->first()->block->name }} <span class="small-muted">{{ $group->first()->block->academicYear->name }} / Semester {{ $group->first()->block->semester }}</span></h2>
    <div class="row g-3">@foreach($group as $classroom)<div class="col-md-6 col-xl-4">@include('classes.card')</div>@endforeach</div>
@empty
    <div class="empty">No assigned classes yet. {{ auth()->user()->role==='admin' ? 'Create a class in Academic Management → Classes & Teacher Assignments.' : 'Your administrator can set up your academic placement.' }}</div>
@endforelse
@endsection
