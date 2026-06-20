<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;

class SuperAdminSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $email = trim((string) config('admin.email', ''));
        $password = (string) config('admin.password', '');
        $name = trim((string) config('admin.name', 'Super Admin'));

        if ($email === '' || $password === '') {
            if (app()->environment('production')) {
                Log::warning('SuperAdminSeeder skipped: set SUPERADMIN_EMAIL and SUPERADMIN_PASSWORD in Coolify environment variables.');

                return;
            }

            $email = 'admin@infracontrol.local';
            $password = 'password';
            $name = $name !== '' ? $name : 'Admin';
        }

        $user = User::query()->firstOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'role' => User::ROLE_ADMIN,
                'password' => $password,
                'email_verified_at' => now(),
            ],
        );

        if (! $user->wasRecentlyCreated && $user->role !== User::ROLE_ADMIN) {
            $user->update(['role' => User::ROLE_ADMIN]);
        }

        Log::info('SuperAdminSeeder ensured admin user exists.', [
            'email' => $email,
            'created' => $user->wasRecentlyCreated,
        ]);
    }
}
