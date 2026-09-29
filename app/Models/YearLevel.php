<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class YearLevel extends Model
{
    protected $fillable = [
        0 => 'name',
        1 => 'level',
    ];

    protected $casts = [
    ];

    public function blocks()
    {
        return $this->hasMany(Block::class);
    }
}
