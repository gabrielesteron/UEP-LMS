@extends('layouts.app')
@section('title','Verify email')
@section('content')<div class="card"><div class="card-body"><h1>Verify your email</h1><p>Open the verification link sent to your email to access your academic workspace.</p><form method="post" action="/email/verification-notification">@csrf<button class="btn btn-primary">Resend verification email</button></form></div></div>@endsection
