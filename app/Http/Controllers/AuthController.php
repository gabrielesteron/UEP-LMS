<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;

class AuthController extends Controller
{
    public function login(Request $r)
    {
        $data = $r->validate(['email' => 'required|email', 'password' => 'required|string']);
        $key = Str::lower($data['email']).'|'.$r->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            return back()->withErrors(['email' => 'Too many attempts. Try again in '.RateLimiter::availableIn($key).' seconds.']);
        }
        if (! Auth::attempt($data + ['status' => 'active'], $r->boolean('remember'))) {
            RateLimiter::hit($key, 60);

            return back()->withErrors(['email' => 'The credentials are invalid or the account is inactive.'])->onlyInput('email');
        }
        RateLimiter::clear($key);
        $r->session()->regenerate();

        return redirect()->intended('/dashboard');
    }

    public function logout(Request $r)
    {
        Auth::logout();
        $r->session()->invalidate();
        $r->session()->regenerateToken();

        return redirect('/login');
    }

    public function forgot(Request $r)
    {
        $r->validate(['email' => 'required|email']);
        Password::sendResetLink($r->only('email'));

        return back()->with('success', 'If that account exists, a reset link has been sent.');
    }

    public function reset(Request $r)
    {
        $r->validate(['token' => 'required', 'email' => 'required|email', 'password' => ['required', 'confirmed', PasswordRule::min(12)->mixedCase()->numbers()]]);
        $status = Password::reset($r->only('email', 'password', 'password_confirmation', 'token'), function (User $user, string $password) {
            $user->forceFill(['password' => Hash::make($password), 'remember_token' => Str::random(60)])->save();
            DB::table('sessions')->where('user_id', $user->id)->delete();
            event(new PasswordReset($user));
        });

        return $status === Password::PASSWORD_RESET ? redirect('/login')->with('success', __($status)) : back()->withErrors(['email' => __($status)]);
    }

    public function activation(Request $r, User $user)
    {
        abort_unless($user->status === 'inactive' && ! $user->email_verified_at, 403);
        abort_unless(Password::broker()->tokenExists($user, (string) $r->query('token')), 403);

        return view('auth.activate', compact('user'));
    }

    public function activate(Request $r, User $user)
    {
        abort_unless($user->status === 'inactive' && ! $user->email_verified_at, 403);
        $r->validate(['password' => ['required', 'confirmed', PasswordRule::min(12)->mixedCase()->numbers()]]);
        abort_unless(Password::broker()->tokenExists($user, (string) $r->query('token')), 403);
        $user->update(['password' => $r->password, 'email_verified_at' => now(), 'status' => 'active']);
        Password::broker()->deleteToken($user);
        event(new Verified($user));

        return redirect('/login')->with('success', 'Email verified and account activated. Sign in with your new password.');
    }

    public function profile(Request $r)
    {
        return view('auth.profile', ['user' => $r->user()]);
    }

    public function updateProfile(Request $r)
    {
        $data = $r->validate(['name' => 'required|string|max:120', 'profile_picture' => 'nullable|image|mimes:jpg,jpeg,png|max:2048']);
        if ($r->hasFile('profile_picture')) {
            $data['profile_picture'] = $r->file('profile_picture')->store('profiles', 'local');
        }
        $r->user()->update($data);

        return back()->with('success', 'Profile updated.');
    }

    public function changePassword(Request $r)
    {
        $r->validate(['current_password' => 'required|current_password', 'password' => ['required', 'confirmed', PasswordRule::min(12)->mixedCase()->numbers()]]);
        $r->user()->update(['password' => $r->password, 'remember_token' => Str::random(60)]);
        DB::table('sessions')->where('user_id', $r->user()->id)->where('id', '!=', $r->session()->getId())->delete();
        $r->session()->regenerate();

        return back()->with('success','Password changed. Other sessions have been signed out.');
    }
}
