<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Assignment extends Model
{
    protected $fillable = [
        0 => 'teacher_assignment_id',
        1 => 'title',
        2 => 'description',
        3 => 'instructions',
        4 => 'due_at',
        5 => 'total_points',
        6 => 'allow_text',
        7 => 'path',
        8 => 'original_name',
        9 => 'status',
    ];

    protected $casts = [
        'due_at' => 'datetime',
        'allow_text' => 'boolean',
    ];

    public function classroom()
    {
        return $this->belongsTo(TeacherAssignment::class, 'teacher_assignment_id');
    }

    public function submissions()
    {
        return $this->hasMany(AssignmentSubmission::class);
    }
}
