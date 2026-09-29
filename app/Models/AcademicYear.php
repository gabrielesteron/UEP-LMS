<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AcademicYear extends Model
{
    protected $fillable = [
        0 => 'name',
        1 => 'starts_on',
        2 => 'ends_on',
    ];

    protected $casts = [
        'starts_on' => 'date',
        'ends_on' => 'date',
    ];

    public function blocks()
    {
        return $this->hasMany(Block::class);
    }
}
