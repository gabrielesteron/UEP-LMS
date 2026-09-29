<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExcuseRequest extends Model
{
    protected $fillable = [
        0 => 'attendance_record_id',
        1 => 'reason',
        2 => 'status',
        3 => 'review_note',
    ];

    protected $casts = [
    ];

    public function record()
    {
        return $this->belongsTo(AttendanceRecord::class, 'attendance_record_id');
    }
}
