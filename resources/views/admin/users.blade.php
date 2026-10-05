@extends('layouts.app')
@section('title', $adminsOnly ? 'Admin Management' : 'User Accounts')
@section('content')
<div class="d-flex justify-content-between flex-wrap gap-3 mb-4"><div><div class="eyebrow">SYSTEM ADMINISTRATION</div><h1>{{ $adminsOnly ? 'Admin Management' : 'User Accounts' }}</h1></div><a class="btn btn-primary align-self-center" href="/admin/manage/users/create{{ $adminsOnly ? '?role=admin' : '' }}">{{ $adminsOnly ? 'Invite Admin' : 'Invite User' }}</a></div>
<p class="small-muted">New accounts activate through their email invitation. Archiving preserves academic records. Restored accounts require a status review before access is reactivated.</p>
<div class="card"><div class="card-body">
<form class="row g-2 mb-4" method="get">
    <div class="col-md-4"><input class="form-control" name="q" value="{{ request('q') }}" placeholder="Search name or email" aria-label="Search users" maxlength="120"></div>
    @unless($adminsOnly)<div class="col-md-2"><select class="form-select" name="role" aria-label="Role"><option value="">All roles</option>@foreach(\App\Models\User::ROLES as $role => $label)<option value="{{ $role }}" @selected(request('role') === $role)>{{ $label }}</option>@endforeach</select></div>@endunless
    <div class="col-md-2"><select class="form-select" name="status" aria-label="Status"><option value="">All statuses</option>@foreach(['active','inactive','suspended'] as $status)<option @selected(request('status') === $status)>{{ $status }}</option>@endforeach</select></div>
    <div class="col-md-2"><select class="form-select" name="archived" aria-label="Account list"><option value="0">Current accounts</option><option value="1" @selected(request()->boolean('archived'))>Archived accounts</option></select></div>
    <div class="col-auto"><button class="btn btn-outline-secondary">Filter</button></div>
    <div class="col-auto"><a class="btn btn-light" href="{{ $adminsOnly ? '/super-admin/admins' : '/admin/manage/users' }}">Reset</a></div>
</form>
<div class="table-responsive"><table class="table"><thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Status</th><th>Verified</th><th>Actions</th></tr></thead><tbody>
@forelse($rows as $row)
<tr><td>{{ $row->name }}</td><td>{{ $row->email }}</td><td>{{ $row->role_label }}</td><td>{{ $row->status }}</td><td>{{ $row->email_verified_at ? 'Yes' : 'Awaiting activation' }}</td><td><div class="d-flex gap-2 flex-wrap">
@if($row->trashed())
    <form method="post" action="/super-admin/users/{{ $row->id }}/restore" data-confirm="Restore this account without activating access?">@csrf<button class="btn btn-sm btn-outline-primary">Restore</button></form>
@else
    <a class="btn btn-sm btn-outline-secondary" href="/admin/manage/users/{{ $row->id }}/edit">Edit</a>
    @if($row->id !== auth()->id())<form method="post" action="/admin/manage/users/{{ $row->id }}" data-confirm="Archive this account and suspend access? Academic records will be preserved.">@csrf @method('DELETE')<button class="btn btn-sm btn-outline-danger">Archive</button></form>@endif
    @if(!$row->email_verified_at && $row->status === 'inactive')<form method="post" action="/admin/users/{{ $row->id }}/invite">@csrf<button class="btn btn-sm btn-outline-primary">Resend invitation</button></form>@endif
@endif
</div></td></tr>
@empty<tr><td colspan="6">No accounts match your filters.</td></tr>@endforelse
</tbody></table></div>{{ $rows->links() }}</div></div>
@endsection
