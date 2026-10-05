<?php

namespace App\Http\Controllers;

use App\Models\AdministrativeAuditLog;
use App\Models\Setting;
use App\Models\User;
use App\Services\AdministrativeAudit;
use App\Services\UserAccounts;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SuperAdminController extends Controller
{
    public function admins(Request $request)
    {
        return app(AdminController::class)->index($request, 'users', true);
    }

    public function roles()
    {
        return view('super-admin.roles', ['roles' => User::ROLES]);
    }

    public function settings()
    {
        return view('super-admin.settings');
    }

    public function saveSettings(Request $request)
    {
        $data = $request->validate(['lms_name' => 'required|string|max:120', 'institution_name' => 'required|string|max:120']);
        $this->persist($request, $data, 'setting.changed');
        return back()->with('success', 'System settings saved.');
    }

    public function features()
    {
        return view('super-admin.features');
    }

    public function saveFeatures(Request $request)
    {
        $request->validate(['show_advanced_features' => 'required|boolean']);
        $this->persist($request, ['lms_show_advanced_features' => $request->boolean('show_advanced_features') ? '1' : '0'], 'feature.changed');
        return back()->with('success', 'Feature control saved. Existing routes and data are retained.');
    }

    private function persist(Request $request, array $data, string $action): void
    {
        DB::transaction(function () use ($request, $data, $action) {
            foreach ($data as $key => $value) {
                $row = Setting::where('key', $key)->lockForUpdate()->first();
                $before = $row?->value;
                if ($before !== $value) {
                    Setting::updateOrCreate(['key' => $key], ['value' => $value]);
                    AdministrativeAudit::record($request->user(), $action, 'setting', null, ['key' => $key, 'before' => $before, 'after' => $value]);
                }
            }
        }, 3);
    }

    public function audit(Request $request)
    {
        $data = $request->validate(['action' => 'nullable|string|max:64', 'from' => 'nullable|date', 'to' => 'nullable|date'.($request->filled('from') ? '|after_or_equal:from' : '')]);
        $query = AdministrativeAuditLog::with('actor');
        if (! empty($data['action'])) {
            $query->where('action', $data['action']);
        }
        foreach (['from' => '>=', 'to' => '<='] as $key => $operator) {
            if (! empty($data[$key])) {
                $query->whereDate('created_at', $operator, $data[$key]);
            }
        }
        return view('super-admin.audit', ['logs' => $query->latest('id')->paginate(25)->withQueryString()]);
    }

    public function information()
    {
        // A strict display whitelist; never render environment/config dumps.
        return view('super-admin.information', ['information' => ['Application' => config('lms.lms_name'), 'Laravel' => app()->version(), 'PHP' => PHP_VERSION, 'Environment' => app()->environment(), 'Database driver' => config('database.default'), 'Debug mode' => config('app.debug') ? 'Enabled' : 'Disabled']]);
    }

    public function restore(Request $request, int $id)
    {
        UserAccounts::restore($request->user(), $id);
        return back()->with('success', 'Account restored without reactivating access. Review its status before activation.');
    }
}
