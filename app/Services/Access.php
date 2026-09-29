<?php

namespace App\Services;

use App\Models\Announcement;
use App\Models\Block;
use App\Models\TeacherAssignment;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class Access
{
    public static function classes(User $user): Builder
    {
        $q = TeacherAssignment::query()->with(['block.program', 'block.yearLevel', 'block.academicYear', 'subject', 'teacher.user']);
        abort_unless(in_array($user->role, ['admin', 'teacher', 'student'], true), 403);
        if ($user->role === 'teacher') {
            $q->whereHas('teacher', fn ($q) => $q->where('user_id', $user->id));
        }
        if ($user->role === 'student') {
            $q->whereHas('enrollments.student', fn ($q) => $q->where('user_id', $user->id));
        }

        return $q;
    }

    public static function classroom(User $user, TeacherAssignment $classroom, bool $write = false): void
    {
        abort_unless(in_array($user->role, ['admin', 'teacher', 'student']) && self::classes($user)->whereKey($classroom->id)->exists(), 403);
        if ($write) {
            abort_unless(in_array($user->role, ['admin', 'teacher']), 403);
        }
    }

    public static function announcements(User $user): Builder
    {
        $q = Announcement::query()->with('user');
        if ($user->role === 'admin') {
            return $q;
        }
        $classScopes = self::classes($user)->withoutEagerLoads()->get(['id', 'block_id']);
        $classes = $classScopes->pluck('id');
        $blocks = $classScopes->pluck('block_id');
        if ($user->student) {
            $blocks->push($user->student->block_id);
        }
        $programs = Block::whereIn('id', $blocks)->pluck('program_id');

        return $q->where(function ($q) use ($classes, $blocks, $programs) {
            $q->where(fn ($q) => $q->whereNull('teacher_assignment_id')->whereNull('program_id')->whereNull('block_id'))
                ->orWhereIn('teacher_assignment_id', $classes)
                ->orWhereIn('block_id', $blocks)->orWhereIn('program_id', $programs);
        });
    }
}
