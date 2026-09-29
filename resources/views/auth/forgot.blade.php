@extends('layouts.app')
@section('title','Forgot password')
@section('content')<div class="auth-card"><h1>Reset your password</h1><p>Enter your account email to receive a password reset link.</p><form method="post" action="/forgot-password">@csrf<label class="form-label" for="email">Email</label><input id="email" class="form-control mb-3" name="email" type="email" required><button class="btn btn-primary">Send reset link</button></form><a class="d-block mt-3" href="/login">Back to sign in</a></div>@endsection
