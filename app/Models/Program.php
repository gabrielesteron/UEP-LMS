<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Program extends Model
{
    protected $fillable = [
        0 => 'code',
        1 => 'name',
    ];

    protected $casts = [
    ];

    public function blocks()
    {
        return $this->hasMany(Block::class);
    }
}
