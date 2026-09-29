<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AttendanceSession extends Model
{
    protected $fillable = [
        0 => 'teacher_assignment_id',
        1 => 'date',
        2 => 'start_time',
        3 => 'end_time',
        4 => 'late_threshold',
    ];

    protected $casts = [
        'date' => 'date',
    ];

    public function classroom()
    {
        return $this->belongsTo(TeacherAssignment::class, 'teacher_assignment_id');
    }

    public function records()
    {
        return $this->hasMany(AttendanceRecord::class);
    }
}
