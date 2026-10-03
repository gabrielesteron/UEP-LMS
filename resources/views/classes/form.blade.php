@extends('layouts.app')
@section('title',Str::headline($kind))
@section('content')
<h1>{{ $row->exists?'Edit':'Create' }} {{ Str::singular($kind) }}</h1><p class="text-secondary">{{ $classroom->label }}</p>
<div class="card" style="max-width:850px"><div class="card-body">
    @if($kind==='quizzes')<p class="small-muted">Create a draft first. Add questions on the quiz page, then return here to publish. Quizzes with attempts are locked.</p>@endif
    <form method="post" enctype="multipart/form-data" action="/classes/{{ $classroom->id }}/content/{{ $kind }}{{ $row->exists?'/'.$row->id:'' }}">
        @csrf
        @if($row->exists) @method('PUT') @endif
        @php($contentFields = $fields)
        @if($kind==='assignments')
            @include('components.fields', ['fields' => array_intersect_key($contentFields, array_flip(['title','instructions','due_at','total_points','attachment']))])
            <details class="mb-4" @if($errors->has('description') || $errors->has('allow_text')) open @endif>
                <summary>More Options</summary><div class="mt-3">
                    @include('components.fields', ['fields' => array_intersect_key($contentFields, array_flip(['description','allow_text']))])
                </div>
            </details>
        @else
            @include('components.fields', ['fields' => array_diff_key($contentFields, ['status' => true])])
        @endif
        <p class="small-muted">Current status: <strong>{{ ucfirst($row->status ?? 'draft') }}</strong>. Drafts are visible to staff only; published content is visible to enrolled students.</p>
        @error('status')<p class="text-danger">{{ $message }}</p>@enderror
        <div class="d-flex flex-wrap gap-2">
            <button class="btn btn-outline-primary" name="status" value="draft">Save Draft</button>
            <button class="btn btn-primary" name="status" value="published">Publish</button>
            <a href="/classes/{{ $classroom->id }}#learning" class="btn btn-light">Cancel</a>
        </div>
    </form>
</div></div>
@endsection
