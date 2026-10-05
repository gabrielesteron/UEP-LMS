@extends('layouts.app')
@section('title','Roles & Permissions')
@section('content')
<h1 class="mb-4">Roles & Permissions</h1><p class="small-muted">Permissions follow the existing fixed roles and class ownership checks. Assign roles through <a href="/admin/manage/users">User Accounts</a>; linked academic profiles retain their matching role.</p>
<div class="card"><div class="card-body"><div class="table-responsive"><table class="table"><thead><tr><th>Role</th><th>System Administration</th><th>Academic Management</th><th>Learning & Activities / Reports</th><th>Profile</th></tr></thead><tbody>
@foreach($roles as $role => $label)<tr><td>{{ $label }}</td><td>{{ $role === 'super_admin' ? 'Full access' : 'Denied' }}</td><td>{{ in_array($role,['super_admin','admin']) ? 'Full academic access' : 'Own assigned/enrolled classes' }}</td><td>{{ in_array($role,['super_admin','admin']) ? 'School-wide oversight' : ($role === 'teacher' ? 'Own classes: teaching, attendance and grading' : 'Own enrollment: published content, submissions and progress') }}</td><td>Own account</td></tr>@endforeach
</tbody></table></div></div></div>
@endsection
