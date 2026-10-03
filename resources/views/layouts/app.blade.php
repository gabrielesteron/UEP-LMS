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
    $navigation = [
        'Academic Management' => $portalUser->role === 'admin' ? [
            '/admin/setup' => '+ Set Up School Year',
            '/admin/manage/academic-years' => 'Academic Years',
            '/admin/manage/programs' => 'Programs',
            '/admin/manage/year-levels' => 'Year Levels',
            '/admin/manage/blocks' => 'Blocks',
            '/admin/manage/subjects' => 'Subjects',
            '/admin/manage/students' => 'Students',
            '/admin/manage/teachers' => 'Teachers',
            '/admin/manage/teacher-assignments' => 'Classes & Teacher Assignments',
            '/admin/manage/users' => 'User Accounts',
        ] : ['/classes' => 'My Classes'],
        'Learning & Activities' => [
            '/classes?module=learning' => $portalUser->role === 'admin' ? 'Monitor Class Activities' : 'Lessons, Materials & Assignments',
        ],
        'Student Monitoring' => [
            ...($portalUser->role === 'teacher' ? ['/classes?module=monitoring' => 'Record Attendance & Grades'] : []),
            '/reports/attendance' => $portalUser->role === 'admin' ? 'Attendance Reports' : 'Attendance',
            '/reports/grades' => $portalUser->role === 'admin' ? 'Grade Reports' : 'Grades',
            ...($portalUser->role === 'student' ? ['/schedule' => 'Schedule'] : []),
        ],
    ];
    if (config('lms.show_advanced_features')) {
        $navigation['Learning & Activities']['/search'] = 'Search Learning Content';
        $navigation['Learning & Activities']['/notifications'] = 'Notifications';
        if ($portalUser->role === 'admin') {
            $navigation['Student Monitoring']['/admin/settings'] = 'Attendance Settings';
        }
    }
@endphp
<a class="skip-link" href="#main-content">Skip to content</a>
<div class="sidebar-backdrop" id="sidebarBackdrop" hidden></div>
<aside class="sidebar" id="sidebar" aria-label="Primary navigation">
    <button class="btn btn-sm btn-outline-light d-lg-none mb-3" type="button" id="menuClose">Close menu</button>
    <a class="brand" href="/dashboard"><span class="brand-mark">U</span><span>UEP <b>LMS</b><small>THE CAMPUS LEARNING PORTAL</small></span></a>
    <a class="nav-item {{ request()->is('dashboard') ? 'active' : '' }}" href="/dashboard" @if(request()->is('dashboard')) aria-current="page" @endif>Overview</a>
    @foreach($navigation as $module => $links)
        <nav aria-label="{{ $module }}" data-core-module>
            <div class="nav-label">{{ $module }}</div>
            @foreach($links as $url => $label)
                @php
                    $path = parse_url($url, PHP_URL_PATH);
                    $active = $path === '/classes'
                        ? request()->is('classes') && request('module', 'academic') === (str_contains($url, 'module=learning') ? 'learning' : (str_contains($url, 'module=monitoring') ? 'monitoring' : 'academic'))
                        : request()->is(ltrim($path, '/'), ltrim($path, '/').'/*');
                @endphp
                <a class="nav-item {{ $active ? 'active' : '' }}" href="{{ $url }}" @if($active) aria-current="page" @endif>{{ $label }}</a>
            @endforeach
        </nav>
    @endforeach
    <nav aria-label="Announcements and profile" class="mt-4 border-top border-secondary pt-2">
        <a class="nav-item {{ request()->is('announcements') ? 'active' : '' }}" href="/announcements" @if(request()->is('announcements')) aria-current="page" @endif>Announcements</a>
        <a class="nav-item {{ request()->is('profile*') ? 'active' : '' }}" href="/profile" @if(request()->is('profile*')) aria-current="page" @endif>My Profile</a>
    </nav>
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
