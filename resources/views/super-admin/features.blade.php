@extends('layouts.app')
@section('title','Feature Controls')
@section('content')
<h1 class="mb-4">Feature Controls</h1>
<div class="card" style="max-width:760px"><div class="card-body"><p class="small-muted">The existing advanced-feature flag controls advanced interface tools. Hidden features, routes and academic data remain available under their existing permissions.</p>
<form method="post" action="/super-admin/features" data-confirm="Save the advanced-feature interface setting?">@csrf @method('PUT')
<input type="hidden" name="show_advanced_features" value="0"><div class="form-check mb-3"><input class="form-check-input" type="checkbox" id="advanced" name="show_advanced_features" value="1" @checked(old('show_advanced_features', config('lms.show_advanced_features')))><label class="form-check-label" for="advanced">Show advanced features</label></div>
<button class="btn btn-primary">Save Feature Control</button></form></div></div>
@endsection
