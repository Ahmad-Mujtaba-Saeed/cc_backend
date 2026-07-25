<?php

namespace Modules\AccessControl\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\AccessControl\Models\Role;
use Modules\AccessControl\Models\Permission;

class RolePermissionSeeder extends Seeder
{
    public function run()
    {
        // Create permissions
        $permissions = [
            'base-user',
            'view-dashboard',
            'manage-users',
            'manage-roles',
            'manage-permissions',
            'manage-plans',
            'manage-settings',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate([
                'name' => ucwords(str_replace('-', ' ', $permission)),
                'slug' => $permission,
            ]);
        }

        // Create admin role
        $adminRole = Role::firstOrCreate([
            'name' => 'Admin',
            'slug' => 'admin',
            'description' => 'Administrator with all permissions'
        ]);

        // Create user role
        $userRole = Role::firstOrCreate([
            'name' => 'User',
            'slug' => 'user',
            'description' => 'User with different permissions'
        ]);

        $userRole->permissions()->sync([
            Permission::where('slug', 'base-user')->first()->id,
        ]);

        // Assign all permissions to admin role
        $adminRole->permissions()->sync(Permission::pluck('id'));

    }
}