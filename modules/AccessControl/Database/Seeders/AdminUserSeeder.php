<?php

namespace Modules\AccessControl\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\User\Models\User;
use Illuminate\Support\Facades\Hash;
use Modules\AccessControl\Models\Role;

class AdminUserSeeder extends Seeder
{
    public function run()
    {
        // Make sure the admin role exists
        $adminRole = Role::where('slug', 'admin')->firstOrFail();

        // Create or update admin user
        $admin = User::updateOrCreate(
            ['email' => 'admin@admin.com'],
            [
                'name' => 'Admin',
                'phone' => '1234567890',
                'password' => Hash::make('AIProj@techtrack'),
                'email_verified_at' => now(),
            ]
        );

        // Assign admin role
        $admin->roles()->syncWithoutDetaching([$adminRole->id]);
    }
}