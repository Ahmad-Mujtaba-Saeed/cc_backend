<?php

namespace Modules\AccessControl\Database\Seeders;

use Illuminate\Database\Seeder;

class AccessControlDatabaseSeeder extends Seeder
{
    public function run()
    {
        $this->call([
            RolePermissionSeeder::class,
            AdminUserSeeder::class,
        ]);
    }
}