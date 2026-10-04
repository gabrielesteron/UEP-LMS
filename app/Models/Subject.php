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
        'program_id', 'year_level_id', 'semester', 'lecture_units', 'laboratory_units',
    ];

    protected $casts = [
        'units' => 'decimal:2',
        'lecture_units' => 'decimal:2',
        'laboratory_units' => 'decimal:2',
        'program_id' => 'integer',
        'year_level_id' => 'integer',
        'semester' => 'integer',
    ];

    public function program()
    {
        return $this->belongsTo(Program::class);
    }

    public function yearLevel()
    {
        return $this->belongsTo(YearLevel::class);
    }

    public function classAssignments()
    {
        return $this->hasMany(TeacherAssignment::class);
    }

    public function prerequisites()
    {
        return $this->belongsToMany(self::class, 'subject_prerequisites', 'subject_id', 'prerequisite_id');
    }

    public function requiredBy()
    {
        return $this->belongsToMany(self::class, 'subject_prerequisites', 'prerequisite_id', 'subject_id');
    }

    public function getCatalogLabelAttribute(): string
    {
        return $this->code.' — '.$this->name.($this->program_id ? ' · '.$this->program->code.' / '.$this->yearLevel->name.' / S'.$this->semester : '');
    }
}
