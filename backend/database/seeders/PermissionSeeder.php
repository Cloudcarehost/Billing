<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Support\PermissionCatalog;
use Illuminate\Database\Seeder;

class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        foreach (PermissionCatalog::definitions() as $permission) {
            Permission::query()->updateOrCreate(
                ['code' => $permission['code']],
                $permission,
            );
        }
    }
}
