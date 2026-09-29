<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Lesson extends Model
{
    protected $fillable = [
        0 => 'teacher_assignment_id',
        1 => 'title',
        2 => 'description',
        3 => 'content',
        4 => 'position',
        5 => 'status',
    ];

    protected $casts = [
    ];

    public function classroom()
    {
        return $this->belongsTo(TeacherAssignment::class, 'teacher_assignment_id');
    }
}
