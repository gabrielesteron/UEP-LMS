<?php

namespace Database\Seeders;

use App\Models\AcademicYear;
use App\Models\Announcement;
use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\AttendanceLog;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSession;
use App\Models\Block;
use App\Models\ClassSchedule;
use App\Models\Enrollment;
use App\Models\Grade;
use App\Models\LearningMaterial;
use App\Models\Lesson;
use App\Models\Program;
use App\Models\Quiz;
use App\Models\QuizQuestion;
use App\Models\Setting;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\TeacherAssignment;
use App\Models\User;
use App\Models\YearLevel;
use App\Notifications\PortalNotice;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            throw new \RuntimeException('Demo seeding is disabled in production. Use lms:create-admin for real deployments.');
        }
        if (User::where('email', 'admin@example.com')->exists()) {
            $this->command?->warn('Demo data already exists; skipped.');

            return;
        }
        DB::transaction(function () {
            $password = 'CampusDemo!2026';
            $admin = User::create(['name' => 'Demo Administrator', 'email' => 'admin@example.com', 'password' => $password, 'role' => 'admin', 'status' => 'active', 'email_verified_at' => now()]);
            $year = AcademicYear::create(['name' => '2026–2027', 'starts_on' => '2026-08-01', 'ends_on' => '2027-05-31']);
            $program = Program::create(['code' => 'BSIT', 'name' => 'BS Information Technology']);
            $level = YearLevel::create(['name' => '3rd Year', 'level' => 3]);
            YearLevel::insert([['name' => '1st Year', 'level' => 1], ['name' => '2nd Year', 'level' => 2], ['name' => '4th Year', 'level' => 4]]);
            $blocks = [];
            foreach (['3J', '3K'] as $name) {
                $blocks[] = Block::create(['academic_year_id' => $year->id, 'program_id' => $program->id, 'year_level_id' => $level->id, 'name' => $name, 'semester' => 1]);
            }
            $teachers = [];
            foreach (['Alex Rivera', 'Morgan Santos', 'Taylor Cruz'] as $i => $name) {
                $user = User::create(['name' => 'Demo '.$name, 'email' => 'teacher'.($i ? $i + 1 : '').'@example.com', 'password' => $password, 'role' => 'teacher', 'status' => 'active', 'email_verified_at' => now()]);
                $teachers[] = Teacher::create(['user_id' => $user->id, 'employee_number' => 'DEMO-T00'.($i + 1)]);
            }
            $students = [];
            foreach (['Jamie Flores', 'Casey Reyes', 'Avery Dela Paz', 'Riley Lim', 'Jordan Tan', 'Quinn Ramos', 'Sky Mendoza', 'Robin Garcia'] as $i => $name) {
                $user = User::create(['name' => 'Demo '.$name, 'email' => 'student'.($i ? $i + 1 : '').'@example.com', 'password' => $password, 'role' => 'student', 'status' => 'active', 'email_verified_at' => now()]);
                $students[] = Student::create(['user_id' => $user->id, 'student_number' => 'DEMO-2026-'.str_pad($i + 1, 4, '0', STR_PAD_LEFT), 'block_id' => $blocks[$i < 5 ? 0 : 1]->id]);
            }
            $subjects = [];
            foreach (['PF102' => 'Programming Fundamentals II', 'NET102' => 'Networking II', 'SIA101' => 'Systems Integration and Architecture', 'CC106' => 'Applications Development', 'GE ELEC 4' => 'Living in the IT Era', 'UED101' => 'University Education'] as $code => $name) {
                $subjects[] = Subject::create(['code' => $code, 'name' => $name, 'description' => 'Build practical knowledge through guided lessons, hands-on work, and collaborative learning.', 'units' => 3, 'status' => 'active']);
            }
            foreach ($blocks as $b => $block) {
                foreach ($subjects as $i => $subject) {
                    $class = TeacherAssignment::create(['teacher_id' => $teachers[($i + $b) % 3]->id, 'block_id' => $block->id, 'subject_id' => $subject->id]);
                    $members = collect($students)->where('block_id', $block->id);
                    foreach ($members as $student) {
                        Enrollment::create(['student_id' => $student->id, 'teacher_assignment_id' => $class->id]);
                    }
                    ClassSchedule::create(['teacher_assignment_id' => $class->id, 'day' => ($i % 3) + 1, 'start_time' => sprintf('%02d:00', 8 + intdiv($i, 3) * 3 + $b), 'end_time' => sprintf('%02d:00', 9 + intdiv($i, 3) * 3 + $b), 'room' => 'IT Lab '.($b + 1)]);
                    Lesson::create(['teacher_assignment_id' => $class->id, 'title' => 'Getting started with '.$subject->code, 'description' => 'Your first steps and learning goals for this subject.', 'content' => "Welcome to our class.\n\nLearning objectives\n1. Explain the core ideas using your own examples.\n2. Apply the concepts to a practical problem.\n3. Reflect on your solution and identify improvements.\n\nBefore class\nRead the study guide in Materials. Bring one question to our next meeting.\n\nPractice\nChoose a familiar campus process and describe how the concepts in this subject could improve it.", 'position' => 1, 'status' => 'published']);
                    $path = 'demo/'.$class->id.'-guide.txt';
                    Storage::disk('local')->put($path, "Study guide: {$subject->name}\n\nReview the lesson objectives. Write a short explanation, include one example, and note a question for discussion.\nAll names and records in this installation are fictional demo data.");
                    LearningMaterial::create(['teacher_assignment_id' => $class->id, 'title' => 'Week 1 study guide', 'description' => 'Reading prompts and a checklist for the first week.', 'path' => $path, 'original_name' => $subject->code.'-study-guide.txt', 'status' => 'published']);
                    $assignment = Assignment::create(['teacher_assignment_id' => $class->id, 'title' => 'Activity 1: Apply the fundamentals', 'description' => 'Turn your understanding into a practical example.', 'instructions' => 'Describe a campus problem, propose a solution using the concepts from Lesson 1, and explain two tradeoffs. Submit a 300–500 word response or a document.', 'due_at' => now()->addDays(3 + $i)->setTime(23, 59), 'total_points' => 100, 'allow_text' => true, 'status' => 'published']);
                    $quiz = Quiz::create(['teacher_assignment_id' => $class->id, 'title' => 'Week 1 knowledge check', 'description' => 'A short check of your understanding.', 'time_limit' => 15, 'max_attempts' => 1, 'available_from' => now()->subDay(), 'available_until' => now()->addDays(7), 'status' => 'published']);
                    foreach ([
                        ['What should you do first when solving a new problem?', 'Understand the requirements', 'Write code immediately', 'Ignore constraints', 'Choose random tools', 'a'],
                        ['Which approach helps validate a solution?', 'Skip testing', 'Test against clear requirements', 'Only check appearance', 'Assume it works', 'b'],
                        ['Why document design decisions?', 'To add unnecessary work', 'To hide mistakes', 'To explain tradeoffs and support maintenance', 'To replace testing', 'c'],
                    ] as $q) {
                        QuizQuestion::create(['quiz_id' => $quiz->id, 'question' => $q[0], 'option_a' => $q[1], 'option_b' => $q[2], 'option_c' => $q[3], 'option_d' => $q[4], 'correct_answer' => $q[5], 'points' => 5]);
                    }
                    if ($i === 0) {
                        $student = $members->first();
                        AssignmentSubmission::create(['assignment_id' => $assignment->id, 'student_id' => $student->id, 'version' => 1, 'answer' => 'I propose an online lab reservation system. It should prevent overlapping bookings, show available times, and preserve a record of approvals. Tradeoffs include ease of use versus detailed permissions, and immediate confirmation versus manual review.', 'submitted_at' => now()->subDay(), 'is_late' => false, 'status' => 'graded', 'score' => 92, 'feedback' => 'Clear proposal. Add a specific example of your conflict check.', 'graded_at' => now()]);
                        Grade::create(['teacher_assignment_id' => $class->id, 'student_id' => $student->id, 'source_type' => 'assignment', 'source_id' => $assignment->id, 'title' => $assignment->title, 'score' => 92, 'total_points' => 100]);
                        foreach ([1, 3, 5] as $days) {
                            $session = AttendanceSession::create(['teacher_assignment_id' => $class->id, 'date' => today()->subDays($days), 'start_time' => '08:00', 'end_time' => '09:00', 'late_threshold' => 15]);
                            foreach ($members->values() as $j => $member) {
                                $status = ['present', 'late', 'absent', 'excused'][($j + $days) % 4];
                                $record = AttendanceRecord::create(['attendance_session_id' => $session->id, 'student_id' => $member->id, 'status' => $status, 'minutes_late' => $status === 'late' ? 18 : ($status === 'present' ? 0 : null), 'remarks' => 'Fictional demonstration record']);
                                AttendanceLog::create(['attendance_record_id' => $record->id, 'user_id' => $admin->id, 'before' => null, 'after' => $record->only(['status', 'minutes_late', 'remarks']), 'reason' => 'Demo attendance initialization']);
                            }
                        }
                    }
                }
            }
            Announcement::create(['user_id' => $admin->id, 'title' => 'Welcome to your campus learning portal', 'body' => 'Your classes, learning materials, activities, and attendance are now in one place. Start with My classes and review your weekly schedule. All accounts and academic records in this demonstration are fictional.']);
            Announcement::create(['user_id' => $teachers[0]->user_id, 'teacher_assignment_id' => TeacherAssignment::where('teacher_id', $teachers[0]->id)->where('block_id', $blocks[0]->id)->firstOrFail()->id, 'title' => 'Bring your ideas to our next class', 'body' => 'Read the Week 1 study guide and prepare one example of a campus problem that technology could help solve.']);
            Setting::create(['key' => 'late_threshold', 'value' => '15']);
            foreach ($students as $student) {
                $student->user->notify(new PortalNotice('Welcome to your workspace', 'Explore your classes and upcoming activities.'));
            }
        });
    }
}
