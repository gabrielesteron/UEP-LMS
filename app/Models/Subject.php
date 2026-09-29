<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Subject extends Model
{
    protected $fillable = [
        0 => 'code',
        1 => 'name',
        2 => 'description',
        3 => 'units',
        4 => 'status',
    ];

    protected $casts = [
    ];
}
