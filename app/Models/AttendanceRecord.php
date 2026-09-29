<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AttendanceRecord extends Model
{
    protected $fillable = [
        0 => 'attendance_session_id',
        1 => 'student_id',
        2 => 'status',
        3 => 'minutes_late',
        4 => 'remarks',
    ];

    protected $casts = [
    ];

    public function session()
    {
        return $this->belongsTo(AttendanceSession::class, 'attendance_session_id');
    }

    public function student()
    {
        return $this->belongsTo(Student::class, 'student_id');
    }

    public function logs()
    {
        return $this->hasMany(AttendanceLog::class);
    }

    public function excuse()
    {
        return $this->hasOne(ExcuseRequest::class);
    }
}
