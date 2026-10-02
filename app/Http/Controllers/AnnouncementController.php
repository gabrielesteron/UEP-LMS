<?php

namespace App\Http\Controllers;

use App\Models\Announcement;
use App\Models\TeacherAssignment;
use App\Models\User;
use App\Notifications\PortalNotice;
use App\Services\Access;
use Illuminate\Http\Request;

class AnnouncementController extends Controller
{
    public function index(Request $r)
    {
        return view('announcements', ['announcements' => Access::announcements($r->user())->with('classroom.block.program', 'classroom.subject', 'block', 'program')->latest()->paginate(15), 'classes' => Access::classes($r->user())->get()]);
    }

    public function save(Request $r, ?int $id = null)
    {
        abort_unless(in_array($r->user()->role, ['admin', 'teacher']), 403);
        $data = $r->validate(['title' => 'required|string|max:180', 'body' => 'required|string|max:10000', 'teacher_assignment_id' => ($r->user()->role === 'teacher' ? 'required|' : 'nullable|').'exists:teacher_assignments,id', 'program_id' => 'nullable|exists:programs,id', 'block_id' => 'nullable|exists:blocks,id']);
        $row = $id ? Announcement::findOrFail($id) : new Announcement;
        if ($id) {
            abort_unless($r->user()->role === 'admin' || $row->user_id === $r->user()->id, 403);
            if ($r->user()->role === 'teacher') {
                Access::classroom($r->user(), $row->classroom, true);
            }
        }
        if ($r->user()->role === 'teacher') {
            $class = TeacherAssignment::findOrFail($data['teacher_assignment_id'] ?? 0);
            Access::classroom($r->user(), $class, true);
            $data['program_id'] = null;
            $data['block_id'] = null;
        }
        $count = collect($data)->only(['teacher_assignment_id', 'program_id', 'block_id'])->filter()->count();
        if ($count > 1) {
            return back()->withInput()->withErrors(['audience' => 'Choose only one audience. Leave all blank for school-wide (admin only).']);
        }
        $row->fill($data + ['user_id' => $r->user()->id])->save();
        if (! $id) {
            User::where('status', 'active')->chunkById(100, function ($users) use ($row) {
                foreach ($users as $user) {
                    if (Access::announcements($user)->whereKey($row->id)->exists()) {
                        $user->notify(new PortalNotice('New announcement', $row->title, '/announcements'));
                    }
                }
            });
        }

        return back()->with('success', 'Announcement saved.');
    }

    public function delete(Request $r, Announcement $announcement)
    {
        abort_unless($r->user()->role === 'admin' || ($r->user()->role === 'teacher' && $announcement->user_id === $r->user()->id), 403);
        if ($r->user()->role === 'teacher') {
            Access::classroom($r->user(), $announcement->classroom, true);
        }
        $announcement->delete();

        return back()->with('success','Announcement deleted.');
    }
}
