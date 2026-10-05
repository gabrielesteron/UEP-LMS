<?php

namespace App\Console\Commands;

use App\Services\UserAccounts;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

class PromoteSuperAdmin extends Command
{
    protected $signature = 'lms:promote-super-admin {email : Existing active verified administrator} {--force : Explicitly confirm promotion without an interactive prompt}';
    protected $description = 'Promote an existing administrator without changing their password or creating an account';

    public function handle(): int
    {
        $email = (string) $this->argument('email');
        if (! $this->option('force') && ! $this->confirm('Grant full Super Admin authority to '.$email.'?', false)) {
            $this->info('No account changed.');
            return self::SUCCESS;
        }
        try {
            $changed = UserAccounts::promote($email);
        } catch (ValidationException $e) {
            $this->error($e->validator->errors()->first());
            return self::FAILURE;
        }
        $this->info($changed ? 'Existing administrator promoted to Super Admin. Password and academic data unchanged.' : 'Account is already Super Admin; no changes made.');
        return self::SUCCESS;
    }
}
