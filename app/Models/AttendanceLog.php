<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AttendanceLog extends Model
{
    protected $fillable = [
        0 => 'attendance_record_id',
        1 => 'user_id',
        2 => 'before',
        3 => 'after',
        4 => 'reason',
    ];

    protected $casts = [
        'before' => 'array',
        'after' => 'array',
    ];

    public function record()
    {
        return $this->belongsTo(AttendanceRecord::class, 'attendance_record_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
