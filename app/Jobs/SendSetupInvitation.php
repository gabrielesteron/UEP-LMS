<?php

namespace App\Jobs;

use App\Models\User;
use App\Notifications\Invitation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Password;

class SendSetupInvitation implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    public function __construct(public int $userId) {}

    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(): void
    {
        $user = User::find($this->userId);
        if ($user && $user->role === 'student' && $user->status === 'inactive' && ! $user->email_verified_at) {
            $user->notify(new Invitation(Password::broker()->createToken($user)));
        }
    }
}
