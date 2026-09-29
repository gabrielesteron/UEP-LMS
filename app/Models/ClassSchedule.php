<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClassSchedule extends Model
{
    protected $fillable = [
        0 => 'teacher_assignment_id',
        1 => 'day',
        2 => 'start_time',
        3 => 'end_time',
        4 => 'room',
    ];

    protected $casts = [
    ];

    public function classroom()
    {
        return $this->belongsTo(TeacherAssignment::class, 'teacher_assignment_id');
    }
}
