<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QuizQuestion extends Model
{
    protected $fillable = [
        0 => 'quiz_id',
        1 => 'question',
        2 => 'option_a',
        3 => 'option_b',
        4 => 'option_c',
        5 => 'option_d',
        6 => 'correct_answer',
        7 => 'points',
    ];

    protected $casts = [
    ];

    public function quiz()
    {
        return $this->belongsTo(Quiz::class, 'quiz_id');
    }
}
