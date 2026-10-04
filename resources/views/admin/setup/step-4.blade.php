<p class="text-secondary">Assign each subject to a teacher and choose its blocks by program and year level. Curriculum subjects must match the block's Program, Year Level and Semester; shared catalog subjects remain available. Only the selected blocks receive each class. Each block/subject pair can have one teacher. Up to 300 classes per setup.</p>
@if($teachers->isEmpty())
    <div class="alert alert-info">No teacher profiles available. <a href="/admin/manage/teachers/create">Add a teacher</a> before continuing.</div>
@endif
<div data-repeat="assignments">
    @foreach(old('assignments', $draft['assignments'] ?? [['subject'=>0,'teacher_id'=>'','blocks'=>[]]]) as $index=>$assignment)
        @include('admin.setup.assignment-row', ['rowIndex'=>$index])
    @endforeach
</div>
<template data-template="assignments">@include('admin.setup.assignment-row', ['rowIndex'=>'__index__','assignment'=>['subject'=>0,'teacher_id'=>'','blocks'=>[]]])</template>
<button class="btn btn-outline-primary" type="button" data-add="assignments" data-max="100">+ Add Teacher Assignment</button>
