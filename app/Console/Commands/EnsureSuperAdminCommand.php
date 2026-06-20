<?php

namespace App\Console\Commands;

use Database\Seeders\SuperAdminSeeder;
use Illuminate\Console\Command;

class EnsureSuperAdminCommand extends Command
{
    protected $signature = 'infracontrol:ensure-superadmin';

    protected $description = 'Create the super admin user from SUPERADMIN_* environment variables';

    public function handle(): int
    {
        $email = trim((string) config('admin.email', ''));

        if ($email === '' || config('admin.password') === null || config('admin.password') === '') {
            $this->error('Set SUPERADMIN_EMAIL and SUPERADMIN_PASSWORD in the environment first.');

            return self::FAILURE;
        }

        $this->call('db:seed', [
            '--class' => SuperAdminSeeder::class,
            '--force' => true,
        ]);

        $this->info("Super admin ensured: {$email}");

        return self::SUCCESS;
    }
}
