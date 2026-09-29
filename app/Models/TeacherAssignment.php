<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TeacherAssignment extends Model
{
    protected $fillable = [
        0 => 'teacher_id',
        1 => 'block_id',
        2 => 'subject_id',
    ];

    protected $casts = [
    ];

    public function teacher()
    {
        return $this->belongsTo(Teacher::class, 'teacher_id');
    }

    public function block()
    {
        return $this->belongsTo(Block::class, 'block_id');
    }

    public function subject()
    {
        return $this->belongsTo(Subject::class, 'subject_id');
    }

    public function enrollments()
    {
        return $this->hasMany(Enrollment::class);
    }

    public function schedules()
    {
        return $this->hasMany(ClassSchedule::class);
    }

    public function lessons()
    {
        return $this->hasMany(Lesson::class);
    }

    public function materials()
    {
        return $this->hasMany(LearningMaterial::class);
    }

    public function assignments()
    {
        return $this->hasMany(Assignment::class);
    }

    public function quizzes()
    {
        return $this->hasMany(Quiz::class);
    }

    public function getLabelAttribute(): string
    {
        return $this->block->program->code.' '.$this->block->name.' · '.$this->subject->code;
    }
}
