<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Grade extends Model
{
    protected $fillable = [
        0 => 'teacher_assignment_id',
        1 => 'student_id',
        2 => 'source_type',
        3 => 'source_id',
        4 => 'title',
        5 => 'score',
        6 => 'total_points',
    ];

    protected $casts = [
    ];

    public function classroom()
    {
        return $this->belongsTo(TeacherAssignment::class, 'teacher_assignment_id');
    }

    public function student()
    {
        return $this->belongsTo(Student::class, 'student_id');
    }
}
