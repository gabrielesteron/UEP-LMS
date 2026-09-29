<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Campus portal') · UEP LMS</title>
    <link href="{{ asset('vendor/bootstrap.min.css') }}" rel="stylesheet">
    <link href="{{ asset('css/app.css') }}" rel="stylesheet">
</head>
<body>
@auth
@php
    $portalUser = auth()->user();
    $unreadCount = $portalUser->unreadNotifications()->count();
    $navigation = [
        '/dashboard' => 'Overview',
        '/classes' => $portalUser->role === 'teacher' ? 'My blocks & subjects' : 'My classes',
        '/schedule' => 'Class schedule',
        '/announcements' => 'Announcements',
        '/reports/attendance' => 'Attendance',
        '/reports/grades' => 'Grades & reports',
        '/search' => 'Search learning content',
        '/notifications' => 'Notifications',
        '/profile' => 'My profile',
    ];
@endphp
<a class="skip-link" href="#main-content">Skip to content</a>
<div class="sidebar-backdrop" id="sidebarBackdrop" hidden></div>
<aside class="sidebar" id="sidebar" aria-label="Primary navigation">
    <button class="btn btn-sm btn-outline-light d-lg-none mb-3" type="button" id="menuClose">Close menu</button>
    <a class="brand" href="/dashboard"><span class="brand-mark">U</span><span>UEP <b>LMS</b><small>THE CAMPUS LEARNING PORTAL</small></span></a>
    <nav aria-label="Workspace">
        <div class="nav-label">WORKSPACE</div>
        @foreach($navigation as $url => $label)
            @php($active = request()->is(ltrim($url, '/')))
            <a class="nav-item {{ $active ? 'active' : '' }}" href="{{ $url }}" @if($active) aria-current="page" @endif>{{ $label }} @if($url === '/notifications' && $unreadCount)<span class="badge text-bg-light">{{ $unreadCount }}</span>@endif</a>
        @endforeach
    </nav>
    @if($portalUser->role === 'admin')
        <nav aria-label="Administration">
            <div class="nav-label">ADMINISTRATION</div>
            @foreach(\App\Services\Catalog::all() as $resource => $definition)
                @php($active = request()->is('admin/manage/'.$resource.'*'))
                <a class="nav-item {{ $active ? 'active' : '' }}" href="/admin/manage/{{ $resource }}" @if($active) aria-current="page" @endif>{{ Str::headline($resource) }}</a>
            @endforeach
            <a class="nav-item {{ request()->is('admin/settings') ? 'active' : '' }}" href="/admin/settings" @if(request()->is('admin/settings')) aria-current="page" @endif>System settings</a>
        </nav>
    @endif
    <div class="sidebar-note">A focused space for<br>your academic journey.</div>
</aside>
<div class="workspace">
    <header class="topbar">
        <button class="btn btn-light d-lg-none" type="button" id="menuToggle" aria-controls="sidebar" aria-expanded="false">Menu</button>
        <span class="workspace-label text-secondary">Academic workspace / <strong class="text-dark">{{ ucfirst($portalUser->role) }}</strong></span>
        <div class="d-flex gap-3 align-items-center"><span class="user-name">{{ $portalUser->name }}</span><form method="post" action="/logout">@csrf<button class="btn btn-sm btn-outline-secondary">Sign out</button></form></div>
    </header>
    <main id="main-content">
        <nav aria-label="Breadcrumb"><ol class="breadcrumb small"><li class="breadcrumb-item"><a href="/dashboard">Portal</a></li><li class="breadcrumb-item active" aria-current="page">@yield('title', 'Overview')</li></ol></nav>
@else
<div class="auth-shell"><main class="auth-main" id="main-content">
@endauth
        @if(session('success'))<div class="alert alert-success" role="status">{{ session('success') }}</div>@endif
        @if($errors->any())<div class="alert alert-danger" role="alert"><strong>Please check the following:</strong><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
        @yield('content')
    </main>
    @auth<footer>UEP-style learning management system · Independent academic project</footer>@endauth
</div>
<script src="{{ asset('vendor/bootstrap.bundle.min.js') }}" defer></script>
<script src="{{ asset('js/app.js') }}" defer></script>
@stack('scripts')
</body>
</html>
