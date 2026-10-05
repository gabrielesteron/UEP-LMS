<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable implements MustVerifyEmail
{
    use HasFactory, Notifiable, SoftDeletes;

    public const ROLES = ['super_admin' => 'Super Admin', 'admin' => 'Admin', 'teacher' => 'Teacher', 'student' => 'Student'];

    // Roles are assigned explicitly by the authorized account service or trusted console.
    protected $fillable = ['name', 'email', 'password', 'status', 'email_verified_at', 'profile_picture'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return ['email_verified_at' => 'datetime', 'password' => 'hashed'];
    }

    public function student()
    {
        return $this->hasOne(Student::class);
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === 'super_admin';
    }

    public function isAcademicAdmin(): bool
    {
        return in_array($this->role, ['admin', 'super_admin'], true);
    }

    public function getRoleLabelAttribute(): string
    {
        return self::ROLES[$this->role] ?? 'Unknown role';
    }

    public function teacher()
    {
        return $this->hasOne(Teacher::class);
    }
}
