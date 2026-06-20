<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class SuperAdminSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $email = trim((string) env('SUPERADMIN_EMAIL', ''));
        $password = (string) env('SUPERADMIN_PASSWORD', '');
        $name = trim((string) env('SUPERADMIN_NAME', 'Super Admin'));

        if (app()->environment('production')) {
            if ($email === '' || $password === '') {
                $this->command?->warn('SuperAdminSeeder skipped: set SUPERADMIN_EMAIL and SUPERADMIN_PASSWORD in production.');

                return;
            }
        } else {
            $email = $email !== '' ? $email : 'admin@infracontrol.local';
            $password = $password !== '' ? $password : 'password';
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
    }
}
