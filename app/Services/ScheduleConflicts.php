<?php

namespace App\Services;

use App\Models\ClassSchedule;
use App\Models\TeacherAssignment;

class ScheduleConflicts
{
    public static function exists(TeacherAssignment $class, array $data, ?int $except = null): bool
    {
        return ClassSchedule::when($except, fn ($q) => $q->where('id', '!=', $except))
            ->where('day', $data['day'])->where('start_time', '<', $data['end_time'])->where('end_time', '>', $data['start_time'])
            ->whereHas('classroom.block', fn ($q) => $q->where('academic_year_id', $class->block->academic_year_id)->where('semester', $class->block->semester))
            ->where(fn ($q) => $q->where('room', $data['room'])->orWhereHas('classroom', fn ($q) => $q->where('teacher_id', $class->teacher_id)->orWhere('block_id', $class->block_id)))->exists();
    }
}
