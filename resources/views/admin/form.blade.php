@extends('layouts.app')
@section('title',Str::headline($resource))
@section('content')
<h1 class="mb-4">{{ $row->exists?'Edit':'Add' }} {{ Str::singular(Str::headline($resource)) }}</h1>
<div class="card" style="max-width:760px"><div class="card-body">
    @if($resource==='users')
        <p class="small-muted">New accounts receive an activation invitation. Roles are assigned by administrators; administrator accounts are created using the secure console command.</p>
    @endif
    @if($resource==='students')
        <p class="small-muted">Program and year level are inherited from the block. Saving placement enrolls the student in all classes in that block.</p>
    @endif
    @if($resource==='year-levels')
        <p class="small-muted">Use a year level name such as First Year and a whole-number level such as 1. Programs are managed separately; choose the Program and Year Level when creating a Block.</p>
    @endif
    @if($missingRelated)
        <div class="alert alert-info">Create the required related records before saving this form.</div>
    @endif
    <form method="post" action="/admin/manage/{{ $resource }}{{ $row->exists?'/'.$row->id:'' }}">
        @csrf
        @if($row->exists) @method('PUT') @endif
        @include('components.fields')
        <button class="btn btn-primary" @disabled($missingRelated)>Save record</button>
        <a href="/admin/manage/{{ $resource }}" class="btn btn-light">Cancel</a>
    </form>
</div></div>
@endsection
