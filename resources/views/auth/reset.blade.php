@extends('layouts.app')
@section('title','New password')
@section('content')<div class="auth-card"><h1>Choose a password</h1><p>Use at least 12 characters, uppercase and lowercase letters, and a number.</p><form method="post" action="/reset-password">@csrf<input type="hidden" name="token" value="{{ $token }}"><label class="form-label">Email<input class="form-control mb-3" type="email" name="email" value="{{ request('email') }}" required></label>@include('auth.password-fields')<button class="btn btn-primary">Reset password</button></form></div>@endsection
