<?php

namespace App\Services;

use App\Models\Assignment;
use App\Models\LearningMaterial;
use App\Models\Lesson;
use App\Models\Quiz;

class ClassContent
{
    public static function all(): array
    {
        return [
            'lessons' => [Lesson::class, ['title' => 'text', 'description' => 'textarea', 'content' => 'textarea', 'position' => 'number', 'status' => 'select:draft,published']],
            'materials' => [LearningMaterial::class, ['title' => 'text', 'description' => 'textarea', 'attachment' => 'file', 'status' => 'select:draft,published']],
            'assignments' => [Assignment::class, ['title' => 'text', 'description' => 'textarea', 'instructions' => 'textarea', 'due_at' => 'datetime-local', 'total_points' => 'number', 'allow_text' => 'select:1,0', 'attachment' => 'file', 'status' => 'select:draft,published']],
            'quizzes' => [Quiz::class, ['title' => 'text', 'description' => 'textarea', 'time_limit' => 'number', 'max_attempts' => 'number', 'available_from' => 'datetime-local', 'available_until' => 'datetime-local', 'status' => 'select:draft,published']],
        ];
    }

    public static function definition(string $kind): array
    {
        abort_unless(isset(self::all()[$kind]), 404);

        return self::all()[$kind];
    }

    public static function rules(string $kind, bool $creating): array
    {
        $rules = ['title' => 'required|string|max:180', 'description' => 'nullable|string|max:10000', 'status' => 'required|in:draft,published'];

        return $rules + match ($kind) {
            'lessons' => ['content' => 'required|string|max:100000', 'position' => 'required|integer|min:1|max:10000'],
            'materials' => ['attachment' => ($creating ? 'required|' : 'nullable|').Files::RULE],
            'assignments' => ['instructions' => 'required|string|max:50000', 'due_at' => 'required|date', 'total_points' => 'required|numeric|min:0.01|max:999999', 'allow_text' => 'required|boolean', 'attachment' => 'nullable|'.Files::RULE],
            'quizzes' => ['time_limit' => 'required|integer|min:1|max:240', 'max_attempts' => 'required|integer|min:1|max:10', 'available_from' => 'required|date', 'available_until' => 'required|date|after:available_from'],
        };
    }
}
