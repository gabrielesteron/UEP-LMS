@extends('layouts.app')
@section('title','Activate account')
@section('content')<div class="auth-card"><h1>Welcome, {{ $user->name }}</h1><p>Verify {{ $user->email }} and activate your account by choosing a password. Use at least 12 characters, mixed case and a number.</p><form method="post" action="{{ request()->fullUrl() }}">@csrf
@include('auth.password-fields')
<button class="btn btn-primary">Verify email & activate</button></form></div>@endsection
