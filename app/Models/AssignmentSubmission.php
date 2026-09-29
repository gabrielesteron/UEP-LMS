<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AssignmentSubmission extends Model
{
    protected $fillable = [
        0 => 'assignment_id',
        1 => 'student_id',
        2 => 'version',
        3 => 'answer',
        4 => 'path',
        5 => 'original_name',
        6 => 'submitted_at',
        7 => 'is_late',
        8 => 'status',
        9 => 'score',
        10 => 'feedback',
        11 => 'graded_at',
    ];

    protected $casts = [
        'submitted_at' => 'datetime',
        'is_late' => 'boolean',
        'graded_at' => 'datetime',
    ];

    public function assignment()
    {
        return $this->belongsTo(Assignment::class, 'assignment_id');
    }

    public function student()
    {
        return $this->belongsTo(Student::class, 'student_id');
    }
}
