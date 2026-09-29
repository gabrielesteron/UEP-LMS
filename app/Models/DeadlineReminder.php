<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeadlineReminder extends Model
{
    protected $fillable = [
        0 => 'assignment_id',
        1 => 'user_id',
    ];

    protected $casts = [
    ];

    public function assignment()
    {
        return $this->belongsTo(Assignment::class, 'assignment_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
