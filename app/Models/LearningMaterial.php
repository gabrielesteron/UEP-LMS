<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LearningMaterial extends Model
{
    protected $fillable = [
        0 => 'teacher_assignment_id',
        1 => 'title',
        2 => 'description',
        3 => 'path',
        4 => 'original_name',
        5 => 'status',
    ];

    protected $casts = [
    ];

    public function classroom()
    {
        return $this->belongsTo(TeacherAssignment::class, 'teacher_assignment_id');
    }
}
