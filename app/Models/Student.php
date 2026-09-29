<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Student extends Model
{
    protected $fillable = [
        0 => 'user_id',
        1 => 'student_number',
        2 => 'block_id',
    ];

    protected $casts = [
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function block()
    {
        return $this->belongsTo(Block::class, 'block_id');
    }

    public function enrollments()
    {
        return $this->hasMany(Enrollment::class);
    }
}
