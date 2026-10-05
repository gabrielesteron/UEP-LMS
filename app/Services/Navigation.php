<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;

class Navigation
{
    public static function for(User $user): array
    {
        $setup = ['/admin/setup' => 'Set Up Academic Year', '/admin/manage/academic-years' => 'Academic Years', '/admin/manage/programs' => 'Programs', '/admin/manage/year-levels' => 'Year Levels', '/admin/manage/blocks' => 'Blocks', '/admin/manage/subjects' => 'Subjects'];
        $people = ['/admin/manage/students' => 'Students', '/admin/manage/teachers' => 'Teachers'];
        $classes = ['/admin/manage/teacher-assignments' => 'Classes & Teacher Assignments'];
        $learning = ['/classes?module=learning' => 'Monitor Class Activities', '/announcements' => 'Announcements'];
        $reports = ['/reports/attendance' => 'Attendance Reports', '/reports/grades' => 'Grade Reports'];
        if ($user->isSuperAdmin()) {
            return ['System Administration' => ['/admin/manage/users' => 'User Accounts', '/super-admin/admins' => 'Admin Management', '/super-admin/roles' => 'Roles & Permissions', '/super-admin/settings' => 'System Settings', '/super-admin/features' => 'Feature Controls', '/super-admin/audit' => 'Audit Logs', '/super-admin/information' => 'System Information'], 'Academic Management' => $setup + $people + $classes, 'Learning & Activities' => $learning, 'Reports' => $reports, 'Account' => ['/profile' => 'My Profile']];
        }
        if ($user->role === 'admin') {
            return ['Academic Setup' => $setup, 'People' => $people, 'Classes' => $classes, 'Learning & Activities' => $learning, 'Reports' => $reports, 'Account' => ['/profile' => 'My Profile']];
        }
        $navigation = ['Academic Management' => ['/classes' => 'My Classes'], 'Learning & Activities' => ['/classes?module=learning' => 'Lessons, Materials & Assignments'], 'Student Monitoring' => [...($user->role === 'teacher' ? ['/classes?module=monitoring' => 'Record Attendance & Grades'] : []), '/reports/attendance' => 'Attendance', '/reports/grades' => 'Grades', ...($user->role === 'student' ? ['/schedule' => 'Schedule'] : [])]];
        if (config('lms.show_advanced_features')) {
            $navigation['Learning & Activities'] += ['/search' => 'Search Learning Content', '/notifications' => 'Notifications'];
        }
        return $navigation + ['Announcements and profile' => ['/announcements' => 'Announcements', '/profile' => 'My Profile']];
    }

    public static function active(string $url, Request $request): bool
    {
        $path = ltrim(parse_url($url, PHP_URL_PATH), '/');
        if ($path === 'admin/manage/teacher-assignments' && $request->is('admin/manage/enrollments*', 'admin/manage/schedules*')) {
            return true;
        }
        if ($path === 'reports/grades' && $request->user()?->role !== 'teacher' && $request->is('classes/*/gradebook')) {
            return true;
        }
        if ($path === 'reports/attendance' && $request->user()?->isAcademicAdmin() && $request->is('attendance/*')) {
            return true;
        }
        if ($path === 'classes') {
            $module = $request->query('module');
            if (! $module) {
                $module = $request->is('assignments/*', 'submissions/*', 'quizzes/*', 'attempts/*', 'classes/*/content/*') ? 'learning' : ($request->is('attendance/*', 'classes/*/gradebook') ? 'monitoring' : 'academic');
                if ($module === 'academic' && $request->user()?->isAcademicAdmin()) {
                    $module = 'learning';
                }
            }
            parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $query);
            return $request->is('classes', 'classes/*', 'assignments/*', 'submissions/*', 'quizzes/*', 'attempts/*', 'attendance/*') && $module === ($query['module'] ?? 'academic');
        }
        return $request->is($path, $path.'/*');
    }
}
