<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QuizAttempt extends Model
{
    protected $fillable = [
        0 => 'quiz_id',
        1 => 'student_id',
        2 => 'attempt_number',
        3 => 'started_at',
        4 => 'expires_at',
        5 => 'completed_at',
        6 => 'score',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'expires_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function quiz()
    {
        return $this->belongsTo(Quiz::class, 'quiz_id');
    }

    public function student()
    {
        return $this->belongsTo(Student::class, 'student_id');
    }

    public function answers()
    {
        return $this->hasMany(QuizAnswer::class);
    }
}
