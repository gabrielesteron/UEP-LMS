<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Teacher extends Model
{
    protected $fillable = [
        0 => 'user_id',
        1 => 'employee_number',
    ];

    protected $casts = [
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function classes()
    {
        return $this->hasMany(TeacherAssignment::class);
    }
}
