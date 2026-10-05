<?php

namespace Tests\Feature;

use App\Models\AdministrativeAuditLog;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\Invitation;
use App\Services\Navigation;
use App\Services\UserAccounts;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SuperAdminTest extends TestCase
{
    use DatabaseMigrations;

    private function account(string $role, array $attributes = []): User
    {
        return User::factory()->create($attributes + ['role' => $role, 'status' => 'active', 'password' => 'RoleTestPassword123']);
    }

    private function data(User $user, array $overrides = []): array
    {
        return $overrides + $user->only(['name', 'email', 'role', 'status']);
    }

    public function test_all_four_roles_use_existing_login_dashboard_profile_and_logout(): void
    {
        foreach (array_keys(User::ROLES) as $role) {
            $user = $this->account($role);
            $this->post('/login', ['email' => $user->email, 'password' => 'RoleTestPassword123'])->assertRedirect('/dashboard');
            $this->assertAuthenticatedAs($user);
            $this->get('/dashboard')->assertOk()->assertSee($user->role_label);
            $this->get('/profile')->assertOk();
            $this->post('/logout')->assertRedirect('/login');
            $this->assertGuest();
        }
    }

    public function test_every_system_page_and_write_is_denied_to_other_roles(): void
    {
        $target = $this->account('admin');
        foreach (['admin', 'teacher', 'student'] as $role) {
            $this->actingAs($this->account($role));
            foreach (['/admin/manage/users', '/admin/manage/users/create', '/admin/manage/users/'.$target->id.'/edit', '/super-admin/dashboard', '/super-admin/admins', '/super-admin/roles', '/super-admin/settings', '/super-admin/features', '/super-admin/audit', '/super-admin/information'] as $url) {
                $this->get($url)->assertForbidden();
            }
            $this->post('/admin/manage/users', $this->data($target, ['role' => 'super_admin']))->assertForbidden();
            $this->put('/admin/manage/users/'.$target->id, $this->data($target, ['role' => 'super_admin']))->assertForbidden();
            $this->delete('/admin/manage/users/'.$target->id)->assertForbidden();
            $this->post('/admin/users/'.$target->id.'/invite')->assertForbidden();
            $this->post('/super-admin/users/'.$target->id.'/restore')->assertForbidden();
            $this->put('/super-admin/settings', ['lms_name' => 'Unauthorized', 'institution_name' => 'Denied'])->assertForbidden();
            $this->put('/super-admin/features', ['show_advanced_features' => 1])->assertForbidden();
            $this->get('/dashboard')->assertDontSee('System Administration')->assertDontSee('User Accounts')->assertDontSee('Admin Management');
        }
        $this->assertSame('admin', $target->fresh()->role);
        $this->assertDatabaseCount('administrative_audit_logs', 0);
        $this->assertDatabaseCount('settings', 0);
    }

    public function test_guest_inactive_and_unverified_super_admin_cannot_access_system(): void
    {
        $this->get('/super-admin/settings')->assertRedirect('/login');
        $this->actingAs($this->account('super_admin', ['status' => 'suspended']))->get('/super-admin/settings')->assertRedirect('/login');
        $this->actingAs($this->account('super_admin', ['email_verified_at' => null]))->get('/super-admin/settings')->assertRedirect('/verify-email');
    }

    public function test_navigation_links_for_all_roles_load_and_are_unique(): void
    {
        $this->seed();
        foreach (array_keys(User::ROLES) as $role) {
            $user = $role === 'super_admin' ? $this->account($role) : User::where('email', $role.'@example.com')->firstOrFail();
            $this->actingAs($user);
            $page = $this->get('/dashboard')->assertOk();
            $document = new DOMDocument;
            @$document->loadHTML($page->getContent());
            $dom = new DOMXPath($document);
            $urls = array_map(fn ($node) => html_entity_decode($node->getAttribute('href')), iterator_to_array($dom->query('//aside//a[@class and contains(@class,"nav-item")]')));
            $this->assertCount(count(array_unique($urls)), $urls);
            foreach ($urls as $url) {
                $this->get($url)->assertOk()->assertDontSee('@include(')->assertDontSee('@csrf');
            }
            $this->assertContains('/profile', $urls);
            $this->assertContains('/announcements', $urls);
            $this->assertNotContains('/notifications', $urls);
            $this->assertNotContains('/search', $urls);
            $this->assertSame($role === 'super_admin', in_array('/admin/manage/users', $urls));
            $this->assertSame($role === 'super_admin', in_array('/super-admin/settings', $urls));
        }
    }

    public function test_nested_and_query_navigation_states(): void
    {
        foreach ([['/admin/manage/programs','/admin/manage/programs/1/edit'], ['/admin/setup','/admin/setup?step=4'], ['/classes?module=learning','/classes/1/content/assignments/create'], ['/classes?module=learning','/assignments/1'], ['/classes?module=monitoring','/classes/1/gradebook'], ['/classes?module=monitoring','/attendance/sessions/1'], ['/classes','/classes/1'], ['/classes?module=learning','/classes?module=learning&page=2']] as [$url, $path]) {
            $this->assertTrue(Navigation::active($url, Request::create($path)), $path);
        }
        $this->assertFalse(Navigation::active('/classes', Request::create('/classes?module=learning')));
        $this->assertFalse(Navigation::active('/super-admin/settings', Request::create('/super-admin/features')));
    }

    public function test_super_admin_academic_authority_preserves_admin_and_role_alias_boundaries(): void
    {
        $this->seed();
        $super = $this->account('super_admin');
        $this->actingAs($super);
        foreach (['/admin/setup', '/admin/setup?step=1', '/admin/manage/programs', '/admin/manage/subjects', '/admin/manage/students', '/admin/manage/teachers', '/classes/1', '/classes/1/content/lessons/create', '/classes/1/gradebook', '/reports/attendance', '/reports/grades', '/admin/settings'] as $url) {
            $this->get($url)->assertOk();
        }
        $this->get('/admin/dashboard')->assertRedirect('/dashboard');
        $this->get('/teacher/dashboard')->assertForbidden();
        $this->get('/student/dashboard')->assertForbidden();
        $this->post('/admin/manage/programs', ['code' => 'NEW', 'name' => 'Test Academic Program'])->assertSessionHasNoErrors()->assertRedirect('/admin/manage/programs');
        $this->actingAs(User::where('email', 'admin@example.com')->first())->get('/admin/setup')->assertOk();
        $this->get('/admin/manage/programs')->assertOk();
        $this->assertSame('admin', User::where('email', 'admin@example.com')->first()->role);
    }

    public function test_self_and_last_super_admin_lockout_actions_are_blocked(): void
    {
        $super = $this->account('super_admin');
        $before = $super->fresh()->getAttributes();
        $this->actingAs($super);
        foreach ([['role' => 'admin'], ['status' => 'inactive'], ['status' => 'suspended'], ['email' => 'replacement@example.com']] as $change) {
            $this->put('/admin/manage/users/'.$super->id, $this->data($super, $change))->assertSessionHasErrors('record');
        }
        $this->delete('/admin/manage/users/'.$super->id)->assertSessionHasErrors('record');
        $this->assertSame($before, $super->fresh()->getAttributes());
        $this->assertDatabaseCount('administrative_audit_logs', 0);
        $this->account('super_admin');
        $this->delete('/admin/manage/users/'.$super->id)->assertSessionHasErrors('record');
    }

    public function test_stale_actor_cannot_remove_the_last_remaining_super_admin(): void
    {
        $actor = $this->account('super_admin');
        $last = $this->account('super_admin');
        $actor->newQuery()->whereKey($actor->id)->update(['role' => 'admin']);
        try {
            UserAccounts::archive($actor, $last->id);
            $this->fail('Stale role must not authorize account mutations.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $this->assertFalse($last->fresh()->trashed());
        $this->assertSame('super_admin', $last->fresh()->role);
    }

    public function test_account_role_changes_are_explicit_and_audited_without_credentials(): void
    {
        $actor = $this->account('super_admin');
        $target = $this->account('admin');
        $password = $target->password;
        $this->actingAs($actor)->put('/admin/manage/users/'.$target->id, $this->data($target, ['role' => 'super_admin', 'password' => 'INJECTED_SECRET', 'remember_token' => 'INJECTED_TOKEN']))->assertSessionHasNoErrors();
        $this->assertSame('super_admin', $target->fresh()->role);
        $this->assertSame($password, $target->fresh()->password);
        $this->assertDatabaseHas('administrative_audit_logs', ['actor_id' => $actor->id, 'action' => 'role.changed', 'target_id' => $target->id]);
        $audit = AdministrativeAuditLog::all()->toJson();
        $this->assertStringNotContainsString('INJECTED_SECRET', $audit);
        $this->assertStringNotContainsString('INJECTED_TOKEN', $audit);
        $this->assertStringNotContainsString($password, $audit);
        $this->put('/admin/manage/users/'.$target->id, $this->data($target->fresh(), ['role' => 'admin', 'status' => 'inactive']))->assertSessionHasNoErrors();
        $this->assertSame('admin', $target->fresh()->role);
        $this->assertDatabaseHas('administrative_audit_logs', ['action' => 'status.changed', 'target_id' => $target->id]);
    }

    public function test_linked_profiles_cannot_change_role_and_archive_restore_preserves_relationships(): void
    {
        $this->seed();
        $super = $this->account('super_admin');
        $teacher = User::where('email', 'teacher@example.com')->firstOrFail();
        $profileId = $teacher->teacher->id;
        $counts = DB::table('teacher_assignments')->count();
        $this->actingAs($super)->put('/admin/manage/users/'.$teacher->id, $this->data($teacher, ['role' => 'admin']))->assertSessionHasErrors('role');
        $this->delete('/admin/manage/users/'.$teacher->id)->assertSessionHasNoErrors();
        $this->assertSoftDeleted('users', ['id' => $teacher->id]);
        $this->assertDatabaseHas('teachers', ['id' => $profileId, 'user_id' => $teacher->id]);
        $this->post('/super-admin/users/'.$teacher->id.'/restore')->assertSessionHasNoErrors();
        $this->assertSame('suspended', $teacher->fresh()->status);
        $this->assertSame($counts, DB::table('teacher_assignments')->count());
        $this->assertSame($profileId, $teacher->fresh()->teacher->id);
        $this->put('/admin/manage/users/'.$teacher->id, $this->data($teacher->fresh(), ['status' => 'active']))->assertSessionHasNoErrors();
    }

    public function test_admin_management_is_a_forced_filtered_view_with_archive_restore(): void
    {
        $super = $this->account('super_admin');
        $admin = $this->account('admin', ['name' => 'Academic Operator']);
        $teacher = $this->account('teacher', ['name' => 'Teaching Account']);
        $this->actingAs($super)->get('/super-admin/admins?role=teacher')->assertOk()->assertSee('Academic Operator')->assertDontSee('Teaching Account');
        $this->get('/admin/manage/users?role=teacher')->assertOk()->assertSee('Teaching Account')->assertDontSee('Academic Operator');
        $this->get('/admin/manage/users?status=inactive')->assertOk()->assertSee('No accounts match');
        $this->delete('/admin/manage/users/'.$admin->id)->assertSessionHasNoErrors();
        $this->get('/super-admin/admins?archived=1')->assertOk()->assertSee('Academic Operator')->assertSee('Restore');
        $this->post('/super-admin/users/'.$admin->id.'/restore')->assertSessionHasNoErrors();
        $this->assertSame('suspended', $admin->fresh()->status);
        $this->assertSame('teacher', $teacher->fresh()->role);
    }

    public function test_admin_invitation_activation_password_hash_and_login_reuse_existing_authentication(): void
    {
        Notification::fake();
        $super = $this->account('super_admin');
        $this->actingAs($super)->post('/admin/manage/users', ['name' => 'Invited Admin', 'email' => 'new-admin@example.com', 'role' => 'admin', 'status' => 'active'])->assertSessionHasNoErrors();
        $target = User::where('email', 'new-admin@example.com')->firstOrFail();
        $this->assertSame('admin', $target->role);
        $this->assertSame('inactive', $target->status);
        $this->assertNull($target->email_verified_at);
        Notification::assertSentTo($target, Invitation::class);
        $this->put('/admin/manage/users/'.$target->id, $this->data($target, ['status' => 'active']))->assertSessionHasErrors('status');
        $token = Password::broker()->createToken($target);
        $url = URL::temporarySignedRoute('activate', now()->addHour(), ['user' => $target->id, 'token' => $token]);
        $this->get($url)->assertOk()->assertSee('New password')->assertSee('Confirm new password');
        $this->post($url, ['password' => 'ActivatedAdmin123', 'password_confirmation' => 'ActivatedAdmin123'])->assertRedirect('/login');
        $this->assertTrue(Hash::check('ActivatedAdmin123', $target->fresh()->password));
        $this->assertDatabaseHas('administrative_audit_logs', ['action' => 'account.activated', 'target_id' => $target->id]);
        $this->post('/login', ['email' => $target->email, 'password' => 'ActivatedAdmin123'])->assertRedirect('/dashboard');
        $this->get('/admin/setup')->assertOk();
        $this->get('/super-admin/settings')->assertForbidden();
    }

    public function test_ordinary_mass_assignment_and_profile_requests_cannot_escalate_roles(): void
    {
        foreach (['admin', 'teacher', 'student'] as $role) {
            $user = $this->account($role);
            $user->fill(['role' => 'super_admin'])->save();
            $this->assertSame($role, $user->fresh()->role);
            $this->actingAs($user)->put('/profile', ['name' => 'Profile Updated', 'role' => 'super_admin', 'status' => 'active', 'email_verified_at' => now(), 'email' => 'forged@example.com'])->assertSessionHasNoErrors();
            $this->assertSame($role, $user->fresh()->role);
            $this->assertNotSame('forged@example.com', $user->fresh()->email);
        }
    }

    public function test_settings_and_features_reuse_settings_with_validation_and_audit(): void
    {
        $super = $this->account('super_admin');
        $this->actingAs($super)->get('/super-admin/features')->assertOk();
        $this->assertFalse(config('lms.show_advanced_features'));
        $this->put('/super-admin/settings', ['lms_name' => '', 'institution_name' => 'UEP'])->assertSessionHasErrors('lms_name');
        $this->put('/super-admin/settings', ['lms_name' => 'UEP Portal', 'institution_name' => 'University of Eastern Philippines', 'APP_KEY' => 'NOT_SAVED', 'DB_PASSWORD' => 'NOT_SAVED'])->assertSessionHasNoErrors();
        $this->get('/dashboard')->assertOk()->assertSee('UEP Portal')->assertSee('University of Eastern Philippines');
        $this->assertDatabaseCount('settings', 2);
        $this->put('/super-admin/features', ['show_advanced_features' => 'invalid'])->assertSessionHasErrors('show_advanced_features');
        $this->put('/super-admin/features', ['show_advanced_features' => 1])->assertSessionHasNoErrors();
        $this->actingAs($this->account('teacher'))->get('/dashboard')->assertOk()->assertSee('Search Learning Content')->assertSee('Notifications');
        $this->actingAs($super)->put('/super-admin/features', ['show_advanced_features' => 0])->assertSessionHasNoErrors();
        $this->actingAs($this->account('student'))->get('/dashboard')->assertDontSee('Search Learning Content')->assertDontSee('href="/notifications"', false);
        $this->assertDatabaseHas('settings', ['key' => 'lms_show_advanced_features', 'value' => '0']);
        $audit = AdministrativeAuditLog::all()->toJson();
        $this->assertStringNotContainsString('NOT_SAVED', $audit);
        $this->assertDatabaseHas('administrative_audit_logs', ['action' => 'feature.changed']);
    }

    public function test_system_information_uses_only_safe_display_fields(): void
    {
        config(['app.key' => str_repeat('K', 32), 'database.connections.mysql.password' => 'PRIVATE_DB_PASSWORD', 'mail.mailers.smtp.password' => 'PRIVATE_SMTP_PASSWORD']);
        $this->actingAs($this->account('super_admin'))->get('/super-admin/information')->assertOk()->assertSee('Laravel')->assertSee('PHP')->assertDontSee(str_repeat('K', 32))->assertDontSee('PRIVATE_DB_PASSWORD')->assertDontSee('PRIVATE_SMTP_PASSWORD');
        $this->get('/super-admin/audit?to='.today()->format('Y-m-d'))->assertOk();
        $this->get('/super-admin/audit?from=2026-10-05&to=2026-10-01')->assertSessionHasErrors('to');
    }

    public function test_audit_failure_rolls_back_account_mutation(): void
    {
        $super = $this->account('super_admin');
        $target = $this->account('admin');
        AdministrativeAuditLog::creating(fn () => throw new \RuntimeException('Simulated audit failure'));
        try {
            UserAccounts::save($super, $this->data($target, ['role' => 'super_admin']), $target->id);
            $this->fail('The failure must propagate.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Simulated audit failure', $e->getMessage());
        } finally {
            AdministrativeAuditLog::flushEventListeners();
        }
        $this->assertSame('admin', $target->fresh()->role);
        $this->assertDatabaseCount('administrative_audit_logs', 0);
    }

    public function test_promotion_command_requires_explicit_confirmation_and_keeps_credentials(): void
    {
        $admin = $this->account('admin');
        $password = $admin->password;
        $token = $admin->remember_token;
        $this->artisan('lms:promote-super-admin', ['email' => $admin->email])->expectsConfirmation('Grant full Super Admin authority to '.$admin->email.'?', false)->assertSuccessful();
        $this->assertSame('admin', $admin->fresh()->role);
        $this->artisan('lms:promote-super-admin', ['email' => 'missing@example.com', '--force' => true])->assertFailed();
        $this->artisan('lms:promote-super-admin', ['email' => $admin->email, '--force' => true])->assertSuccessful();
        $this->assertSame('super_admin', $admin->fresh()->role);
        $this->assertSame($password, $admin->fresh()->password);
        $this->assertSame($token, $admin->fresh()->remember_token);
        $this->assertDatabaseCount('administrative_audit_logs', 1);
        $this->artisan('lms:promote-super-admin', ['email' => $admin->email, '--force' => true])->assertSuccessful();
        $this->assertDatabaseCount('administrative_audit_logs', 1);
        foreach ([$this->account('student'), $this->account('admin', ['status' => 'inactive']), $this->account('admin', ['email_verified_at' => null])] as $ineligible) {
            $this->artisan('lms:promote-super-admin', ['email' => $ineligible->email, '--force' => true])->assertFailed();
        }
    }

    public function test_existing_secure_create_admin_command_still_assigns_admin_role(): void
    {
        $this->artisan('lms:create-admin')->expectsQuestion('Administrator name', 'Console Admin')->expectsQuestion('Email', 'console@example.com')->expectsQuestion('Password (12+ characters, mixed case, number)', 'ConsolePassword123')->assertSuccessful();
        $admin = User::where('email', 'console@example.com')->firstOrFail();
        $this->assertSame('admin', $admin->role);
        $this->assertTrue(Hash::check('ConsolePassword123', $admin->password));
        $this->assertFalse(User::where('role', 'super_admin')->exists());
        $this->assertDatabaseHas('administrative_audit_logs', ['action' => 'user.created', 'target_id' => $admin->id]);
    }

    public function test_system_write_actions_keep_csrf_protection(): void
    {
        $super = $this->account('super_admin');
        $this->actingAs($super);
        $this->app['env'] = 'local';
        try {
            $this->put('/super-admin/settings', ['lms_name' => 'Denied', 'institution_name' => 'Denied'])->assertStatus(419);
            $this->put('/super-admin/features', ['show_advanced_features' => 1])->assertStatus(419);
            $this->delete('/admin/manage/users/'.$super->id)->assertStatus(419);
            $this->assertDatabaseCount('administrative_audit_logs', 0);
        } finally {
            $this->app['env'] = 'testing';
        }
    }
}
