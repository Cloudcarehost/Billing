<?php

namespace App\Services;

use App\Models\Hotel;
use App\Models\Permission;
use App\Models\Role;
use App\Support\PermissionCatalog;

class RolePresetService
{
    /** @return array<string, Role> */
    public function createDefaults(Hotel $hotel): array
    {
        Permission::query()->upsert(PermissionCatalog::definitions(), ['code'], ['name', 'group', 'updated_at']);

        $permissionIds = Permission::query()->pluck('id', 'code');
        $presets = [
            'owner' => ['name' => 'Owner', 'is_owner' => true, 'permissions' => array_keys($permissionIds->all())],
            'manager' => ['name' => 'Manager', 'is_owner' => false, 'permissions' => [
                'dashboard.view', 'tables.view', 'tables.configure', 'sessions.open', 'sessions.assign', 'orders.view', 'orders.create', 'orders.manage',
                'orders.cancel', 'orders.cancel_prepared', 'orders.cancel_served', 'orders.cancel_high_value',
                'kitchen.view', 'kitchen.update', 'billing.view', 'billing.request', 'billing.create', 'billing.close_without_sale', 'billing.discount', 'billing.void',
                'billing.refund', 'payments.manage', 'catalog.view', 'catalog.manage', 'inventory.view',
                'inventory.receive', 'inventory.adjust', 'customers.view', 'customers.manage', 'reports.view',
                'reports.export', 'finance.view', 'finance.manage', 'users.view', 'users.manage', 'outlets.all',
            ]],
            'cashier' => ['name' => 'Counter / Cashier', 'is_owner' => false, 'permissions' => [
                'dashboard.view', 'tables.view', 'sessions.open', 'sessions.assign', 'orders.view', 'orders.create', 'orders.cancel', 'billing.view', 'billing.request', 'billing.create',
                'billing.close_without_sale', 'billing.discount', 'payments.manage', 'customers.view', 'customers.manage',
            ]],
            'waiter' => ['name' => 'Waiter', 'is_owner' => false, 'permissions' => [
                'tables.view', 'sessions.open', 'orders.view', 'orders.create', 'orders.cancel', 'billing.request', 'customers.view',
            ]],
            'kitchen' => ['name' => 'Kitchen', 'is_owner' => false, 'permissions' => [
                'kitchen.view', 'kitchen.update', 'orders.view', 'orders.cancel', 'orders.cancel_prepared',
            ]],
        ];

        $roles = [];
        foreach ($presets as $slug => $preset) {
            $role = Role::query()->firstOrCreate(
                ['hotel_id' => $hotel->id, 'slug' => $slug],
                ['name' => $preset['name'], 'is_owner' => $preset['is_owner']],
            );
            $role->permissions()->sync(
                collect($preset['permissions'])->map(fn (string $code) => $permissionIds[$code])->all(),
            );
            $roles[$slug] = $role;
        }

        return $roles;
    }
}
