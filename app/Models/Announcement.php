<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Announcement extends Model
{
    protected $fillable = [
        0 => 'user_id',
        1 => 'teacher_assignment_id',
        2 => 'program_id',
        3 => 'block_id',
        4 => 'title',
        5 => 'body',
    ];

    protected $casts = [
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function classroom()
    {
        return $this->belongsTo(TeacherAssignment::class, 'teacher_assignment_id');
    }

    public function program()
    {
        return $this->belongsTo(Program::class, 'program_id');
    }

    public function block()
    {
        return $this->belongsTo(Block::class, 'block_id');
    }
}
