@php
    $emptyLevel = ['year_level_id' => null, 'name' => '', 'level' => '', 'blocks' => [['name' => '']]];
    $legacyStructure = [['program_id' => $draft['program_id'] ?? null, 'year_levels' => [['year_level_id' => $draft['year_level_id'] ?? null, 'name' => '', 'level' => '', 'blocks' => $draft['blocks'] ?? [['name' => '']]]]]];
    $structureRows = old('structures', $draft['structures'] ?? $legacyStructure);
    $structureRows = is_array($structureRows) && $structureRows ? array_values($structureRows) : $legacyStructure;
@endphp
<p class="text-secondary">Build each program separately: choose its year levels, then name the blocks under each year level. Add up to 20 programs, 12 year levels per program and 30 blocks in total.</p>
<div class="alert alert-info">A block name may be reused in a different program or year level. Within the same program and year level, each block name must be unique. Shared year levels are reused; their catalog names stay unchanged.</div>
@if($programs->isEmpty())
    <div class="alert alert-info">Add at least one <a href="/admin/manage/programs/create">Program (Course)</a> before continuing. You can create year levels within this step.</div>
@endif
<input type="hidden" name="expected_structure_count" value="{{ count($structureRows) }}" data-expected-structures>
<div data-structures>
    @foreach($structureRows as $programIndex => $structure)
        @include('admin.setup.program-row', ['programIndex' => $programIndex, 'structure' => is_array($structure) ? $structure : ['program_id' => null, 'year_levels' => [$emptyLevel]]])
    @endforeach
</div>
<template data-program-template>@include('admin.setup.program-row', ['programIndex' => '__program__', 'structure' => ['program_id' => null, 'year_levels' => [$emptyLevel]]])</template>
<template data-year-level-template>@include('admin.setup.year-level-row', ['programIndex' => '__program__', 'levelIndex' => '__level__', 'levelRow' => $emptyLevel])</template>
<template data-block-template>@include('admin.setup.block-row', ['programIndex' => '__program__', 'levelIndex' => '__level__', 'blockIndex' => '__block__', 'blockName' => ''])</template>
<button type="button" class="btn btn-outline-primary" data-add-program>+ Add Program (Course)</button>
<div class="small text-secondary mt-3" aria-live="polite" data-structure-summary></div>
