<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class PlatformAdminSeeder extends Seeder
{
    public function run(): void
    {
        $email = env('PLATFORM_ADMIN_EMAIL', 'admin@pandupos.local');
        $password = env('PLATFORM_ADMIN_PASSWORD', 'ChangeMe123!');

        if (app()->isProduction() && $password === 'ChangeMe123!') {
            $this->command->warn('Refusing to seed default platform admin password in production. Set PLATFORM_ADMIN_PASSWORD.');

            return;
        }

        $admin = User::updateOrCreate(['email' => $email], [
            'name' => 'Platform Admin',
            'password' => $password,
            'is_platform_admin' => true,
        ]);
        $admin->assignRole('platform-admin');
    }
}
