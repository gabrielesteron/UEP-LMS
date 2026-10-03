<p class="text-secondary">Add up to 30 blocks. Names must be unique within this setup. Changing an earlier step requires reviewing the later choices again.</p>
<div data-repeat="blocks">
    @foreach(old('blocks', $draft['blocks'] ?? [['name'=>'']]) as $index => $block)
        @include('admin.setup.block-row', ['rowIndex'=>$index, 'blockName'=>$block['name']])
    @endforeach
</div>
<template data-template="blocks">@include('admin.setup.block-row', ['rowIndex'=>'__index__', 'blockName'=>''])</template>
<button type="button" class="btn btn-outline-primary" data-add="blocks" data-max="30">+ Add Block</button>
