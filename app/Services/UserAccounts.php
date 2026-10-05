<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class UserAccounts
{
    // Lock privileged accounts in a stable order before locking any other target.
    // All privilege-changing paths use this same transaction and lock order.
    private static function lock(): void
    {
        User::withTrashed()->whereIn('role', ['admin', 'super_admin'])->orderBy('id')->lockForUpdate()->get();
    }

    private static function actor(User $actor): User
    {
        $actor = User::findOrFail($actor->id);
        abort_unless($actor->isSuperAdmin() && $actor->status === 'active' && $actor->email_verified_at, 403);
        return $actor;
    }

    private static function protect(User $actor, User $target, string $role, string $status, bool $emailChanged = false, bool $archive = false): void
    {
        $removesAuthority = $role !== 'super_admin' || $status !== 'active' || $emailChanged || $archive;
        if ($target->isSuperAdmin() && $removesAuthority) {
            if ($target->id === $actor->id) {
                throw ValidationException::withMessages(['record' => 'You cannot deactivate, archive, change the email of, or demote your own Super Admin account.']);
            }
            if ($target->status === 'active' && $target->email_verified_at && ! $target->trashed()
                && User::where('role', 'super_admin')->where('status', 'active')->whereNotNull('email_verified_at')->count() <= 1) {
                throw ValidationException::withMessages(['record' => 'The last active Super Admin must be retained.']);
            }
        }
    }

    public static function save(User $actor, array $data, ?int $id): User
    {
        return DB::transaction(function () use ($actor, $data, $id) {
            self::lock();
            $actor = self::actor($actor);
            $target = $id ? User::whereKey($id)->lockForUpdate()->firstOrFail() : new User;
            abort_unless(isset(User::ROLES[$data['role']]) && in_array($data['status'], ['active', 'inactive', 'suspended'], true), 422);
            $oldRole = $target->role;
            $oldStatus = $target->status;
            $emailChanged = $id && $target->email !== $data['email'];
            if ($id) {
                self::protect($actor, $target, $data['role'], $data['status'], $emailChanged);
                if ($oldRole !== $data['role'] && ($target->student()->exists() || $target->teacher()->exists())) {
                    throw ValidationException::withMessages(['role' => 'This account has an academic profile. Retain its role to preserve academic records.']);
                }
                if (! $target->email_verified_at && $data['status'] === 'active') {
                    throw ValidationException::withMessages(['status' => 'The user must activate their account using the invitation.']);
                }
            }
            $target->fill(['name' => $data['name'], 'email' => $data['email'], 'status' => $data['status']]);
            $target->role = $data['role']; // Explicitly authorized; never mass assigned.
            if (! $id) {
                $target->password = Str::random(48);
                $target->status = 'inactive';
                $target->email_verified_at = null;
            } elseif ($emailChanged) {
                $target->email_verified_at = null;
                $target->status = 'inactive';
            }
            $changed = array_keys($target->getDirty());
            $target->save();
            AdministrativeAudit::record($actor, $id ? 'user.updated' : 'user.created', 'user', $target->id, ['changed_fields' => array_values(array_diff($changed, ['password', 'remember_token']))]);
            if ($id && $oldRole !== $target->role) {
                AdministrativeAudit::record($actor, 'role.changed', 'user', $target->id, ['old_role' => $oldRole, 'new_role' => $target->role]);
            }
            if ($id && $oldStatus !== $target->status) {
                AdministrativeAudit::record($actor, 'status.changed', 'user', $target->id, ['old_status' => $oldStatus, 'new_status' => $target->status]);
            }
            return $target;
        }, 3);
    }

    public static function archive(User $actor, int $id): void
    {
        DB::transaction(function () use ($actor, $id) {
            self::lock();
            $actor = self::actor($actor);
            $target = User::whereKey($id)->lockForUpdate()->firstOrFail();
            self::protect($actor, $target, $target->role, 'suspended', false, true);
            $target->status = 'suspended';
            $target->save();
            $target->delete(); // Existing soft delete preserves profile and academic IDs.
            AdministrativeAudit::record($actor, 'user.archived', 'user', $id);
        }, 3);
    }

    public static function restore(User $actor, int $id): void
    {
        DB::transaction(function () use ($actor, $id) {
            self::lock();
            $actor = self::actor($actor);
            $target = User::onlyTrashed()->whereKey($id)->lockForUpdate()->firstOrFail();
            $target->status = $target->email_verified_at ? 'suspended' : 'inactive';
            $target->restore();
            AdministrativeAudit::record($actor, 'user.restored', 'user', $id);
        }, 3);
    }

    public static function promote(string $email): bool
    {
        return DB::transaction(function () use ($email) {
            self::lock();
            $target = User::where('email', $email)->lockForUpdate()->first();
            if (! $target || ! in_array($target->role, ['admin', 'super_admin'], true) || $target->status !== 'active' || ! $target->email_verified_at || $target->student()->exists() || $target->teacher()->exists()) {
                throw ValidationException::withMessages(['email' => 'Choose an existing active, verified administrator without a Student or Teacher profile.']);
            }
            if ($target->isSuperAdmin()) {
                return false;
            }
            $target->role = 'super_admin';
            $target->save();
            AdministrativeAudit::record(null, 'role.changed', 'user', $target->id, ['old_role' => 'admin', 'new_role' => 'super_admin', 'source' => 'console']);
            return true;
        }, 3);
    }
}
