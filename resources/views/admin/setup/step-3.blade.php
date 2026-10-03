<p class="text-secondary">Select a subject from the recent catalog or enter its code. An existing code reuses that subject without changing its details. Add up to 50 subjects.</p>
<div data-repeat="subjects">
    @foreach(old('subjects', $draft['subjects'] ?? [['id'=>null,'code'=>'','name'=>'','units'=>3]]) as $index => $subject)
        @include('admin.setup.subject-row', ['rowIndex'=>$index])
    @endforeach
</div>
<template data-template="subjects">@include('admin.setup.subject-row', ['rowIndex'=>'__index__','subject'=>['id'=>null,'code'=>'','name'=>'','units'=>3]])</template>
<button class="btn btn-outline-primary" type="button" data-add="subjects" data-max="50">+ Add Subject</button>
