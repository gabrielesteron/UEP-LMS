<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $fillable = [
        0 => 'key',
        1 => 'value',
    ];

    protected $casts = [
    ];
}
